<?php

namespace App\Services\RAG;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RagJudge
{
    public const VERSION = 'rag-judge-v2';

    public function __construct(
        private string $apiKey = '',
        private string $baseUrl = 'https://openrouter.ai/api/v1',
        private string $model = '',
        private int $timeout = 90,
    ) {}

    public function model(): string
    {
        return $this->model !== '' ? $this->model : (string) config('services.llm.chat_model', '');
    }

    public function configured(): bool
    {
        return $this->apiKey !== '' && $this->model() !== '';
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<int, array{chunk_id: int|null, title: string|null, chunk_index: int|null, text: string, score: float|null}>  $contexts
     * @return array<string, mixed>
     */
    public function judge(array $case, array $contexts, string $answer): array
    {
        if (! $this->configured()) {
            return $this->unavailable('judge_not_configured');
        }

        $payload = [
            'question' => $case['question'],
            'expected_answer' => $case['expected_answer'],
            'answerable' => $case['answerable'],
            'answer' => $answer,
            'contexts' => array_map(fn ($c) => [
                'title' => $c['title'],
                'chunk_index' => $c['chunk_index'],
                'text' => mb_substr((string) $c['text'], 0, 1500),
            ], $contexts),
        ];

        $system = 'You are a strict RAG evaluator. Score each dimension from 0.0 to 1.0 and return ONLY a JSON object. '
            .'faithfulness = every claim in the answer is supported by the supplied contexts, regardless of whether the answer is complete, relevant, or matches expected_answer. '
            .'answer_relevancy = the answer addresses the question. '
            .'answer_correctness = the answer agrees with expected_answer (if unanswerable, 1.0 only when it refuses). '
            .'context_precision = the supplied contexts are relevant to the question. '
            .'context_recall = the contexts contain the evidence needed for expected_answer. '
            .'refusal = true only when the answer clearly declines because the knowledge base lacks the answer. '
            .'JSON keys: faithfulness, answer_relevancy, answer_correctness, context_precision, context_recall, refusal, reasoning.';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout($this->timeout)->post($this->baseUrl.'/chat/completions', [
                'model' => $this->model(),
                'temperature' => 0,
                'max_tokens' => 900,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_SLASHES)],
                ],
            ]);

            if (! $response->successful()) {
                return $this->unavailable('http_'.$response->status());
            }

            $raw = (string) data_get($response->json(), 'choices.0.message.content', '');
            $parsed = self::parseJson($raw);
            if ($parsed === null) {
                return $this->unavailable('invalid_json') + ['raw' => mb_substr($raw, 0, 2000)];
            }

            return [
                'faithfulness' => self::clamp($parsed['faithfulness'] ?? null),
                'answer_relevancy' => self::clamp($parsed['answer_relevancy'] ?? null),
                'answer_correctness' => self::clamp($parsed['answer_correctness'] ?? null),
                'context_precision' => self::clamp($parsed['context_precision'] ?? null),
                'context_recall' => self::clamp($parsed['context_recall'] ?? null),
                'refusal' => (bool) ($parsed['refusal'] ?? false),
                'reasoning' => mb_substr((string) ($parsed['reasoning'] ?? ''), 0, 1000),
                'judge_model' => $this->model(),
                'judge_version' => self::VERSION,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::channel('llm')->warning('rag_judge_failed', ['error' => $e->getMessage()]);

            return $this->unavailable($e->getMessage());
        }
    }

    /**
     * Extract a JSON object from a model reply that may contain code fences or
     * prose. Returns null when no object can be decoded.
     *
     * @return array<string, mixed>|null
     */
    public static function parseJson(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw) ?? $raw;
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($raw, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Small repair for trailing commas, a common LLM JSON defect.
        $repaired = preg_replace('/,\s*([}\]])/', '$1', $json);
        $decoded = json_decode((string) $repaired, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Cohen's kappa for two binary label sets (human vs judge).
     *
     * @param  array<int, int|bool>  $human
     * @param  array<int, int|bool>  $judge
     */
    public static function kappa(array $human, array $judge): ?float
    {
        $n = min(count($human), count($judge));
        if ($n === 0) {
            return null;
        }

        $agree = 0;
        $humanYes = 0;
        $judgeYes = 0;
        for ($i = 0; $i < $n; $i++) {
            $h = (int) (bool) $human[$i];
            $j = (int) (bool) $judge[$i];
            $humanYes += $h;
            $judgeYes += $j;
            $agree += $h === $j ? 1 : 0;
        }

        $po = $agree / $n;
        $pe = (($humanYes / $n) * ($judgeYes / $n)) + (((($n - $humanYes) / $n)) * ((($n - $judgeYes) / $n)));

        return $pe >= 1.0 ? 1.0 : round(($po - $pe) / (1 - $pe), 4);
    }

    private static function clamp(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return round(max(0.0, min(1.0, (float) $value)), 4);
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(string $error): array
    {
        return [
            'faithfulness' => null,
            'answer_relevancy' => null,
            'answer_correctness' => null,
            'context_precision' => null,
            'context_recall' => null,
            'refusal' => null,
            'reasoning' => null,
            'judge_model' => $this->model(),
            'judge_version' => self::VERSION,
            'error' => $error,
        ];
    }
}
