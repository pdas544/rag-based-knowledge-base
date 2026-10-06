<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\LLM\LLMFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SummarizeConversationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $conversationId) {}

    public function handle(): void
    {
        $conversation = Conversation::find($this->conversationId);
        if (! $conversation || $conversation->summary) {
            return;
        }

        // Everything except the most recent 10 messages
        $recentIds = $conversation->messages()->orderByDesc('id')->limit(10)->pluck('id');
        $older = $conversation->messages()->whereNotIn('id', $recentIds)
            ->orderBy('id')->get()
            ->map(fn ($m) => "{$m->role}: ".mb_substr($m->content, 0, 500))
            ->implode("\n");
        if (trim($older) === '') {
            return;
        }

        try {
            $out = '';
            foreach (LLMFactory::chat()->streamChat([
                ['role' => 'system', 'content' => 'Summarize this chat history in 5 sentences or less. Keep facts, names, and decisions.'],
                ['role' => 'user', 'content' => $older],
            ]) as $delta) {
                $out .= $delta;
            }
            $conversation->update(['summary' => mb_substr(trim($out) !== '' ? $out : $older, 0, 2000)]);
        } catch (\Throwable $e) {
            Log::channel('llm')->warning('summarization_failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }
}
