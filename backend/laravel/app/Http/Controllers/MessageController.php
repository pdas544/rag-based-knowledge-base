<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\UserQuota;
use App\Services\LLM\LLMFactory;
use App\Services\Qdrant\QdrantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
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

        // 2.10 Retrieval: embed query -> Qdrant top_k=5 threshold 0.72 (ready only).
        // Skipped when no API key (stub mode) or on any retrieval failure.
        $sources = [];
        if (config('services.llm.openrouter_api_key', '') !== '') {
            try {
                $queryVector = LLMFactory::embeddings()->embed($content);
                if ($queryVector !== []) {
                    $hits = app(QdrantService::class)->search($queryVector, 5, 0.72);
                    if ($hits !== []) {
                        $context = 'Use following context to answer the question. Cite sources like [doc].'."\n"
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

        $stream = function () use ($history, $model, $conversation, $user, $userMessage, $content, $started, $sources, &$full) {
            $assistantMessage = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => '',
                'model' => $model,
                'sources' => $sources,
            ]);

            try {
                foreach (LLMFactory::chat()->streamChat($history, ['model' => $model]) as $delta) {
                    $full = $this->appendChunk($assistantMessage, $full, $delta);
                    echo 'data: '.json_encode(['delta' => $delta])."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } catch (\Throwable $e) {
                Log::channel('single')->error('LLM stream failed', ['error' => $e->getMessage()]);
                echo 'data: '.json_encode(['error' => 'LLM request failed'])."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            $latency = round((microtime(true) - $started) * 1000);
            $tokens = (int) (strlen($full) / 4);
            $assistantMessage->update(['content' => $full, 'tokens' => $tokens]);

            $conversation->increment('message_count', 2);
            $conversation->increment('total_tokens', ($userMessage->tokens ?? 0) + $tokens);
            $conversation->update(['last_message_at' => now()]);

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

            Log::channel('single')->info('llm_call', [
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
}
