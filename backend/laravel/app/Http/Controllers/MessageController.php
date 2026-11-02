<?php

namespace App\Http\Controllers;

use App\Jobs\SummarizeConversationJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UserQuota;
use App\Services\LLM\LLMFactory;
use App\Services\Moderation\ModerationService;
use App\Services\RAG\RagPromptBuilder;
use App\Services\RAG\RagRetriever;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    // Deterministic refusal when retrieval ran but nothing cleared the
    // threshold — keeps ungroundable questions precise instead of letting
    // the model drift into its own knowledge.
    public const NO_CONTEXT_MESSAGE = RagPromptBuilder::NO_CONTEXT_MESSAGE;

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

        // Atomic quota reservation (4.13): consume the prompt only after
        // validation/moderation, and only if the cap is not already reached.
        if (! $user->isAdmin() && ! UserQuota::tryConsumePrompt($user->id)) {
            return response()->json(
                ['message' => 'Daily quota exceeded.'],
                429,
                ['Retry-After' => (string) now()->endOfDay()->diffInSeconds(now())]
            );
        }

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
        // from config (0.5 Nemotron-calibrated), then keyword fallback. The
        // shared RagRetriever keeps chat and offline eval on one code path.
        $retrieval = app(RagRetriever::class)->retrieve($content);
        $sources = $retrieval->sources();
        $retrievalAttempted = $retrieval->attempted;

        if (! $retrieval->isEmpty()) {
            $context = app(RagPromptBuilder::class)->build($retrieval->hits);
            array_unshift($history, ['role' => 'system', 'content' => $context]);
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

        $stream = function () use ($history, $model, $conversation, $user, $userMessage, $content, $started, $sources, $retrieval, $refuseWithoutLlm, &$full) {
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

            // Prompt count was already reserved atomically before streaming;
            // only the token usage is recorded here.
            UserQuota::recordTokens($user->id, ($userMessage->tokens ?? 0) + $tokens);

            // Invalidate cache
            Cache::forget("conversation:{$conversation->id}:messages:last10");
            Cache::forget("user:{$user->id}:conversations:list:page:1");

            Log::channel('llm')->info('llm_call', [
                'model' => $model,
                'tokens' => $tokens,
                'latency_ms' => $latency,
                'conversation_id' => $conversation->id,
                'sources' => count($sources),
                'retrieval' => $retrieval->telemetry(),
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
