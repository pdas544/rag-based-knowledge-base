<?php

namespace App\Services\RAG;

use App\Models\DocumentChunk;
use App\Services\LLM\LLMFactory;
use App\Services\LLM\ModelPricing;
use App\Services\Moderation\ModerationService;

class RagEvaluator
{
    /** @var array<string, int|null> */
    private array $chunkIdCache = [];

    public function __construct(
        private RagRetriever $retriever,
        private RagPromptBuilder $prompts,
        private RagJudge $judge,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $cases
     * @param  array{top_k?: int, threshold?: float, chat_model?: string, moderate?: bool, skip_judge?: bool}  $options
     * @return array{cases: array<int, array<string, mixed>>, metrics: array<string, mixed>}
     */
    public function run(array $cases, string $mode, array $options = []): array
    {
        $topK = (int) ($options['top_k'] ?? config('services.rag.top_k', 5));
        $threshold = (float) ($options['threshold'] ?? config('services.qdrant.score_threshold', 0.5));
        $chatModel = (string) ($options['chat_model'] ?? config('services.llm.chat_model', 'openai/gpt-4o-mini'));
        $moderate = (bool) ($options['moderate'] ?? true);
        $skipJudge = (bool) ($options['skip_judge'] ?? false);
        $full = $mode === 'full';

        $rows = [];
        foreach ($cases as $case) {
            $question = (string) $case['question'];
            $relevant = array_map('intval', $case['relevant_chunk_ids']);

            $ranking = $this->retriever->retrieveRanked($question, $topK);
            $production = $this->retriever->retrieve($question, $topK, $threshold, true);

            $rankingIds = $this->chunkIds($ranking->hits);
            $productionIds = $this->chunkIds($production->hits);

            $row = [
                'id' => $case['id'],
                'question' => $question,
                'answerable' => (bool) $case['answerable'],
                'expected_answer' => (string) $case['expected_answer'],
                'tags' => $case['tags'] ?? [],
                'relevant_chunk_ids' => $relevant,
                'retrieval' => [
                    'ranking' => [
                        'ids' => $rankingIds,
                        'latency_ms' => $ranking->latencyMs,
                        'empty' => $ranking->isEmpty(),
                    ],
                    'production' => [
                        'ids' => $productionIds,
                        'latency_ms' => $production->latencyMs,
                        'empty' => $production->isEmpty(),
                        'used_fallback' => $production->usedFallback,
                        'top_score' => $production->topScore,
                        'threshold' => $production->threshold,
                    ],
                ],
                'retrieval_scores' => [
                    'ranking' => $case['answerable'] ? RetrievalMetrics::score($rankingIds, $relevant) : null,
                    'production' => $case['answerable'] ? RetrievalMetrics::score($productionIds, $relevant) : null,
                ],
                'answer' => null,
                'refused' => null,
                'scores' => null,
                'judge' => null,
                'latency_ms' => null,
                'tokens' => null,
                'cost_usd' => 0.0,
            ];

            if ($full) {
                $row = $this->generate($case, $production, $row, $chatModel, $moderate, $skipJudge);
            }

            $rows[] = $row;
        }

        return [
            'cases' => $rows,
            'metrics' => $this->aggregate($rows, $full),
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function generate(
        array $case,
        RetrievalResult $production,
        array $row,
        string $chatModel,
        bool $moderate,
        bool $skipJudge,
    ): array {
        $question = (string) $case['question'];
        $contexts = $this->contexts($production->hits);

        if ($moderate && ($reason = app(ModerationService::class)->check($question))) {
            $row['answer'] = $reason;
            $row['refused'] = true;
            $row['scores'] = ['citation_accuracy' => 1.0];
            $row['judge'] = ['error' => 'moderation_blocked', 'reasoning' => $reason];

            return $row;
        }

        // Deterministic refusal mirrors chat when retrieval ran and nothing
        // cleared the threshold/fallback.
        if ($production->attempted && $production->isEmpty()) {
            $row['answer'] = RagPromptBuilder::NO_CONTEXT_MESSAGE;
            $row['refused'] = true;
            $row['scores'] = ['citation_accuracy' => 1.0];
            $row['tokens'] = (int) (strlen($row['answer']) / 4);
            $row['cost_usd'] = 0.0;

            return $row;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->prompts->build($production->hits)],
            ['role' => 'user', 'content' => $question],
        ];

        $started = microtime(true);
        $answer = '';
        try {
            foreach (LLMFactory::chat()->streamChat($messages, ['model' => $chatModel]) as $delta) {
                $answer .= $delta;
            }
        } catch (\Throwable $e) {
            $row['answer'] = $answer;
            $row['scores'] = ['citation_accuracy' => null, 'error' => $e->getMessage()];
            $row['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
            $row['tokens'] = (int) (strlen($answer) / 4);

            return $row;
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $tokens = (int) (strlen($answer) / 4);
        $judge = $skipJudge ? null : $this->judge->judge($case, $contexts, $answer);
        $citation = self::citationAccuracy($answer, $contexts);
        $refused = self::isRefusal($answer, $judge);

        $scores = [
            'citation_accuracy' => $citation,
        ];
        foreach (['faithfulness', 'answer_relevancy', 'answer_correctness', 'context_precision', 'context_recall'] as $key) {
            $scores[$key] = $judge[$key] ?? null;
        }

        $row['answer'] = $answer;
        $row['refused'] = $refused;
        $row['scores'] = $scores;
        $row['judge'] = $judge;
        $row['latency_ms'] = $latency;
        $row['tokens'] = $tokens;
        $row['cost_usd'] = ModelPricing::estimateCost($chatModel, $tokens);

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregate(array $rows, bool $full): array
    {
        $answerable = array_values(array_filter($rows, fn ($r) => $r['answerable']));
        $unanswerable = array_values(array_filter($rows, fn ($r) => ! $r['answerable']));

        $rankingRows = array_values(array_filter(array_map(fn ($r) => $r['retrieval_scores']['ranking'], $answerable)));
        $productionRows = array_values(array_filter(array_map(fn ($r) => $r['retrieval_scores']['production'], $answerable)));
        $metricKeys = ['recall_at_1', 'recall_at_3', 'recall_at_5', 'precision_at_3', 'precision_at_5', 'mrr_at_5', 'ndcg_at_5', 'hit_at_5'];

        $productionLatencies = array_map(fn ($r) => $r['retrieval']['production']['latency_ms'], $answerable);
        $fallbackRows = array_values(array_filter($answerable, fn ($r) => $r['retrieval']['production']['used_fallback']));
        $fallbackHits = array_values(array_filter(array_map(fn ($r) => $r['retrieval_scores']['production']['hit_at_5'] ?? null, $fallbackRows), fn ($v) => $v !== null));

        $retrievalProduction = RetrievalMetrics::aggregate($productionRows, $metricKeys) + [
            'empty_result_rate' => $answerable === [] ? null : round(count(array_filter($answerable, fn ($r) => $r['retrieval']['production']['empty'])) / count($answerable), 4),
            'fallback_rate' => $answerable === [] ? null : round(count($fallbackRows) / count($answerable), 4),
            'fallback_precision' => $fallbackHits === [] ? null : round(array_sum($fallbackHits) / count($fallbackHits), 4),
            'p50_latency_ms' => RetrievalMetrics::percentile($productionLatencies, 50),
            'p95_latency_ms' => RetrievalMetrics::percentile($productionLatencies, 95),
        ];

        $generation = [];
        if ($full) {
            $scoreRows = array_values(array_filter(array_map(fn ($r) => $r['scores'], $rows)));
            foreach (['answer_correctness', 'faithfulness', 'answer_relevancy', 'context_precision', 'context_recall', 'citation_accuracy'] as $key) {
                $generation[$key] = RetrievalMetrics::aggregate(array_map(fn ($s) => [$key => $s[$key] ?? null], $scoreRows), [$key])[$key];
            }

            $unanswerableJudged = array_values(array_filter($unanswerable, fn ($r) => $r['refused'] !== null));
            $correctRefusals = count(array_filter($unanswerableJudged, fn ($r) => $r['refused']));
            $generation['refusal_accuracy'] = $unanswerableJudged === [] ? null : round($correctRefusals / count($unanswerableJudged), 4);
            $generation['false_answer_rate'] = $unanswerableJudged === [] ? null : round((count($unanswerableJudged) - $correctRefusals) / count($unanswerableJudged), 4);
            $generation['refusals'] = count(array_filter($rows, fn ($r) => $r['refused']));
            $generation['p50_latency_ms'] = RetrievalMetrics::percentile(array_map(fn ($r) => $r['latency_ms'] ?? 0, $rows), 50);
            $generation['p95_latency_ms'] = RetrievalMetrics::percentile(array_map(fn ($r) => $r['latency_ms'] ?? 0, $rows), 95);
            $generation['tokens_total'] = (int) array_sum(array_map(fn ($r) => $r['tokens'] ?? 0, $rows));
            $generation['cost_usd_total'] = round(array_sum(array_map(fn ($r) => $r['cost_usd'] ?? 0, $rows)), 6);
            $generation['tokens_per_query'] = $rows === [] ? null : round($generation['tokens_total'] / count($rows), 1);
            $generation['cost_usd_per_query'] = $rows === [] ? null : round($generation['cost_usd_total'] / count($rows), 6);
        }

        return [
            'counts' => [
                'cases' => count($rows),
                'answerable' => count($answerable),
                'unanswerable' => count($unanswerable),
                'fallback_cases' => count($fallbackRows),
            ],
            'retrieval_ranking' => RetrievalMetrics::aggregate($rankingRows, $metricKeys),
            'retrieval_production' => $retrievalProduction,
            'generation' => $generation,
        ];
    }

    /**
     * @param  array<int, array{point_id: string, score: float|null, payload: array<string, mixed>}>  $hits
     * @return array<int, int>
     */
    private function chunkIds(array $hits): array
    {
        $ids = [];
        foreach ($hits as $hit) {
            $id = $hit['payload']['chunk_id'] ?? $this->chunkId(
                isset($hit['payload']['document_id']) ? (int) $hit['payload']['document_id'] : null,
                isset($hit['payload']['chunk_index']) ? (int) $hit['payload']['chunk_index'] : null,
            );
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private function chunkId(?int $documentId, ?int $chunkIndex): ?int
    {
        if ($documentId === null || $chunkIndex === null) {
            return null;
        }

        $key = "{$documentId}:{$chunkIndex}";
        if (! array_key_exists($key, $this->chunkIdCache)) {
            $this->chunkIdCache[$key] = DocumentChunk::query()
                ->where('document_id', $documentId)
                ->where('chunk_index', $chunkIndex)
                ->value('id');
        }

        return $this->chunkIdCache[$key] !== null ? (int) $this->chunkIdCache[$key] : null;
    }

    /**
     * @param  array<int, array{point_id: string, score: float|null, payload: array<string, mixed>}>  $hits
     * @return array<int, array{chunk_id: int|null, title: string|null, chunk_index: int|null, text: string, score: float|null}>
     */
    private function contexts(array $hits): array
    {
        return array_map(fn ($hit) => [
            'chunk_id' => $hit['payload']['chunk_id'] ?? $this->chunkId(
                isset($hit['payload']['document_id']) ? (int) $hit['payload']['document_id'] : null,
                isset($hit['payload']['chunk_index']) ? (int) $hit['payload']['chunk_index'] : null,
            ),
            'title' => $hit['payload']['doc_title'] ?? null,
            'chunk_index' => isset($hit['payload']['chunk_index']) ? (int) $hit['payload']['chunk_index'] : null,
            'text' => (string) ($hit['payload']['text'] ?? ''),
            'score' => $hit['score'],
        ], $hits);
    }

    /**
     * Every citation must name a context that was actually retrieved (title +
     * chunk index). Refusals with no citations score 1.0; grounded answers with
     * no citation score 0.0.
     *
     * @param  array<int, array{title: string|null, chunk_index: int|null}>  $contexts
     */
    public static function citationAccuracy(string $answer, array $contexts): float
    {
        preg_match_all('/\[([^\[\]]+?)\s+ch(\d+)\]/i', $answer, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return self::isRefusal($answer, null) ? 1.0 : 0.0;
        }

        $valid = 0;
        foreach ($matches as $match) {
            $title = mb_strtolower(trim($match[1]));
            $index = (int) $match[2];
            foreach ($contexts as $context) {
                $contextTitle = mb_strtolower(trim((string) ($context['title'] ?? '')));
                if ((int) ($context['chunk_index'] ?? -1) !== $index) {
                    continue;
                }
                if ($title === $contextTitle
                    || ($contextTitle !== '' && str_contains($contextTitle, $title))
                    || ($title !== '' && str_contains($title, $contextTitle))) {
                    $valid++;
                    break;
                }
            }
        }

        return round($valid / count($matches), 4);
    }

    /**
     * Judge verdict when available, otherwise a conservative string check.
     *
     * @param  array<string, mixed>|null  $judge
     */
    public static function isRefusal(string $answer, ?array $judge): bool
    {
        if ($judge !== null && isset($judge['refusal']) && $judge['refusal'] !== null) {
            return (bool) $judge['refusal'];
        }

        $lower = mb_strtolower(trim($answer));

        return str_contains($lower, mb_strtolower(RagPromptBuilder::NO_CONTEXT_MESSAGE))
            || (bool) preg_match('/\b(not found|cannot|can\'t|do not have|don\'t have|no information|unable to|not available|insufficient)\b/i', $lower);
    }
}
