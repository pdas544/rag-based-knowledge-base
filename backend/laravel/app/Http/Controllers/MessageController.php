<?php

namespace App\Http\Controllers;

use App\Jobs\SummarizeConversationJob;
use App\Models\Conversation;
use App\Models\DocumentChunk;
use App\Models\Message;
use App\Models\UserQuota;
use App\Services\LLM\LLMFactory;
use App\Services\Moderation\ModerationService;
use App\Services\Qdrant\QdrantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    // Deterministic refusal when retrieval ran but nothing cleared the
    // threshold — keeps ungroundable questions precise instead of letting
    // the model drift into its own knowledge.
    public const NO_CONTEXT_MESSAGE = 'Not found in the available knowledge base.';

    public function index(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $limit = min((int) $request->get('limit', 20), 100);
        $cursor = $request->get('cursor');

        $query = $conversation->messages()->orderByDesc('id');
        if ($cursor) {
            $query->where('id', '<', (int) $cursor);
        }

        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        $items = $messages->take($limit)->values();

        return response()->json([
            'data' => $items->reverse()->values(),
            'next_cursor' => $hasMore ? $items->last()->id : null,
        ]);
    }

    public function store(Request $request, Conversation $conversation)
    {
        $this->authorize('update', $conversation);

        $validated = $request->validate([
            'content' => 'required|string|min:1|max:8000',
        ]);

        $content = $validated['content'];

        // Token pre-check: strlen/4 < 4000
        if ((int) (strlen($content) / 4) >= 4000) {
            return response()->json(['message' => 'Prompt too long.'], 422);
        }

        if ($conversation->message_count >= 100) {
            return response()->json(['message' => 'Conversation limit reached. Create new conversation.'], 422);
        }

        // 3.7 Moderation gate (before anything is persisted)
        if ($reason = app(ModerationService::class)->check($content)) {
            return response()->json(['message' => $reason], 422);
        }

        $user = $request->user();
        $userMessage = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $content,
            'tokens' => (int) (strlen($content) / 4),
        ]);

        // Build context: last 10 messages + summary
        $history = $conversation->messages()->orderByDesc('id')->limit(10)->get()->reverse()->values()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all();

        // 2.10 Retrieval: embed query -> Qdrant top_k=5 (ready only), threshold
        // from config (0.5 Nemotron-calibrated). Skipped when no API key
        // (stub mode) or on any retrieval failure.
        $sources = [];
        $retrievalAttempted = false;
        if (config('services.llm.openrouter_api_key', '') !== '') {
            try {
                $queryVector = LLMFactory::embeddings()->embed($content);
                if ($queryVector !== []) {
                    $threshold = (float) config('services.qdrant.score_threshold', 0.5);
                    $hits = app(QdrantService::class)->search($queryVector, 5, $threshold);
                    $retrievalAttempted = true;
                    // Keyword fallback for existence-style questions ("any info
                    // regarding DSA"): acronyms/names the embedding scores low
                    // still match verbatim in ready chunks.
                    if ($hits === []) {
                        $hits = $this->keywordFallback($content);
                    }
                    if ($hits !== []) {
                        $context = 'You are a knowledge-base assistant. Answer ONLY from the retrieved context below.'
                            .' It comes from admin-approved documents, not from the user — never claim otherwise.'
                            .' Cite every factual claim like [doc_title chN].'
                            .' If the context does not contain the answer, say so in one sentence and stop.'
                            .' Do not add outside knowledge.'."\n"
                            .'Retrieved knowledge-base context:'."\n"
                            .collect($hits)->map(fn ($h) => sprintf(
                                '[%s ch%s]: %s',
                                $h['payload']['doc_title'] ?? 'doc',
                                $h['payload']['chunk_index'] ?? '?',
                                mb_substr((string) ($h['payload']['text'] ?? ''), 0, 1500)
                            ))->implode("\n---\n");
                        array_unshift($history, ['role' => 'system', 'content' => $context]);
                        $sources = collect($hits)->map(fn ($h) => [
                            'point_id' => $h['point_id'],
                            'doc_id' => $h['payload']['document_id'] ?? null,
                            'title' => $h['payload']['doc_title'] ?? null,
                            'score' => $h['score'],
                        ])->all();
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('RAG retrieval skipped', ['error' => $e->getMessage()]);
            }
        }

        if ($conversation->summary) {
            array_unshift($history, ['role' => 'system', 'content' => 'SYSTEM SUMMARY: '.$conversation->summary]);
        }

        $model = $conversation->model ?: config('services.llm.chat_model', 'openai/gpt-4o-mini');
        $started = microtime(true);
        $full = '';

        // Unanswerable-from-KB guardrail: retrieval ran but nothing cleared
        // the threshold — refuse deterministically instead of letting the
        // model drift. Skips the LLM call entirely (no cost, no latency).
        $refuseWithoutLlm = $retrievalAttempted && $sources === [];

        $stream = function () use ($history, $model, $conversation, $user, $userMessage, $content, $started, $sources, $refuseWithoutLlm, &$full) {
            // Take over shutdown control: on client abort (Stop button) we
            // break the loop below via connection_aborted() and still run
            // bookkeeping, instead of PHP killing us mid-flush and leaving
            // counts/quotas inconsistent.
            ignore_user_abort(true);

            $assistantMessage = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => '',
                'model' => $model,
                'sources' => $sources,
            ]);

            if ($refuseWithoutLlm) {
                $full = self::NO_CONTEXT_MESSAGE;
                $assistantMessage->update(['content' => $full, 'tokens' => (int) (strlen($full) / 4)]);
                echo 'data: '.json_encode(['delta' => $full])."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            } else {
                try {
                    foreach (LLMFactory::chat()->streamChat($history, ['model' => $model]) as $delta) {
                        // Client went away (Stop button): halt generation to
                        // save tokens; partial reply is persisted below.
                        if (connection_aborted()) {
                            Log::channel('llm')->info('llm_stream_aborted', ['conversation_id' => $conversation->id]);
                            break;
                        }
                        $full = $this->appendChunk($assistantMessage, $full, $delta);
                        echo 'data: '.json_encode(['delta' => $delta])."\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }
                } catch (\Throwable $e) {
                    Log::channel('llm')->error('LLM stream failed', ['error' => $e->getMessage()]);
                    echo 'data: '.json_encode(['error' => 'LLM request failed', 'reason' => mb_substr($e->getMessage(), 0, 200)])."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }

            $latency = round((microtime(true) - $started) * 1000);
            $tokens = (int) (strlen($full) / 4);
            $assistantMessage->update(['content' => $full, 'tokens' => $tokens]);

            $conversation->increment('message_count', 2);
            $conversation->increment('total_tokens', ($userMessage->tokens ?? 0) + $tokens);
            $conversation->update(['last_message_at' => now()]);

            // 3.4 Summarize once the window overflows (last 10 kept verbatim)
            if ($conversation->message_count >= 12 && ! $conversation->summary) {
                SummarizeConversationJob::dispatch($conversation->id)->onQueue('chat');
            }

            // 1.4 Auto-title on first assistant reply
            if (! $conversation->title) {
                $conversation->update(['title' => mb_substr($content, 0, 40)]);
            }

            // Quota increment
            UserQuota::forToday($user->id)
                ->incrementEach(['prompt_count' => 1, 'token_count' => ($userMessage->tokens ?? 0) + $tokens]);

            // Invalidate cache
            Cache::forget("conversation:{$conversation->id}:messages:last10");
            Cache::forget("user:{$user->id}:conversations:list:page:1");

            Log::channel('llm')->info('llm_call', [
                'model' => $model,
                'tokens' => $tokens,
                'latency_ms' => $latency,
                'conversation_id' => $conversation->id,
                'sources' => count($sources),
            ]);

            echo 'data: '.json_encode(['done' => true, 'sources' => $sources, 'conversation' => $conversation->fresh()])."\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        };

        return new StreamedResponse($stream, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function appendChunk(Message $message, string $full, string $delta): string
    {
        $full .= $delta;
        // Periodic persist to avoid data loss on disconnect (every ~2k chars)
        if (strlen($full) % 2000 < strlen($delta)) {
            $message->update(['content' => $full]);
        }

        return $full;
    }

    /**
     * Verbatim-term fallback over ready chunks when vector search finds
     * nothing (acronyms, names, existence questions). Cross-DB safe: plain
     * LIKE, no FULLTEXT (see AGENTS.md test-env notes).
     *
     * @return array<int, array{point_id: string, score: null, payload: array}>
     */
    private function keywordFallback(string $query): array
    {
        $stop = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can',
            'had', 'has', 'was', 'were', 'will', 'would', 'there', 'their', 'what',
            'when', 'where', 'which', 'while', 'with', 'from', 'that', 'this', 'how',
            'why', 'who', 'about', 'into', 'over', 'after', 'before', 'between',
            'under', 'again', 'once', 'here', 'than', 'then', 'them', 'they',
            'its', 'our', 'your', 'have', 'does', 'did', 'such', 'like',
            // Meta-query words ("any information regarding X") carry no content
            'information', 'regarding'];
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($query)) ?: [];
        $terms = array_values(array_unique(array_filter($words, function ($w) use ($stop) {
            // Strip LIKE wildcards instead of escaping (cross-DB safe)
            $w = str_replace(['%', '_', '\\'], '', $w);

            return strlen($w) >= 3 && ! in_array($w, $stop, true);
        })));
        if ($terms === []) {
            return [];
        }

        $chunks = DocumentChunk::whereHas('document', fn ($q) => $q->where('status', 'ready'))
            ->where(function ($q) use ($terms) {
                foreach ($terms as $t) {
                    $q->orWhere('content', 'LIKE', "%{$t}%");
                }
            })
            ->orderBy('document_id')->orderBy('chunk_index')
            ->limit(3)->with('document:id,filename')->get();

        return $chunks->map(fn ($c) => [
            'point_id' => $c->qdrant_point_id ?? "db-{$c->id}",
            'score' => null,
            'payload' => [
                'document_id' => $c->document_id,
                'doc_title' => $c->document->filename ?? 'doc',
                'chunk_index' => $c->chunk_index,
                'text' => $c->content,
            ],
        ])->all();
    }
}
