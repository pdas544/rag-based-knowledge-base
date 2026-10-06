<?php

namespace App\Services\Moderation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ModerationService
{
    /**
     * Returns a block reason when the text must be rejected, null when clean.
     * Fail-open: any checker error allows the request (logged).
     */
    public function check(string $text): ?string
    {
        foreach ($this->patterns() as $pattern) {
            if (preg_match($pattern, $text)) {
                Log::channel('llm')->warning('moderation_blocked', [
                    'rule' => 'blocklist',
                    'pattern' => $pattern,
                    'excerpt' => mb_substr($text, 0, 200),
                ]);

                return 'Message blocked by content policy.';
            }
        }

        $model = (string) config('services.moderation.model', '');
        if ($model === '' || config('services.llm.openrouter_api_key', '') === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.llm.openrouter_api_key'),
                'Content-Type' => 'application/json',
            ])->timeout(15)->post(config('services.llm.openrouter_base_url').'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a content moderator. Reply with exactly ALLOW or BLOCK.'],
                    ['role' => 'user', 'content' => mb_substr($text, 0, 2000)],
                ],
                'temperature' => 0,
            ]);
            $verdict = trim((string) data_get($response->json(), 'choices.0.message.content', 'ALLOW'));
            if (str_starts_with(mb_strtoupper($verdict), 'BLOCK')) {
                Log::channel('llm')->warning('moderation_blocked', ['rule' => 'llm', 'model' => $model]);

                return 'Message blocked by content policy.';
            }
        } catch (\Throwable $e) {
            Log::channel('llm')->warning('moderation_checker_failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /** @return array<int, string> */
    private function patterns(): array
    {
        $extra = array_filter(array_map(trim(...), explode(',', (string) config('services.moderation.extra_patterns', ''))));

        return array_merge([
            '/ignore\s+(all\s+)?previous\s+instructions/i',
            '/reveal\s+(your|the)\s+(system\s+)?prompt/i',
            '/\b(system\s*:\s*you are now)\b/i',
        ], array_map(fn ($p) => '/'.str_replace('/', '\/', $p).'/i', $extra));
    }
}
