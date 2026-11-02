<?php

namespace Tests\Unit;

use App\Services\RAG\RagJudge;
use App\Services\RAG\RetrievalMetrics;
use PHPUnit\Framework\TestCase;

class RetrievalMetricsTest extends TestCase
{
    public function test_ir_metrics_for_one_query(): void
    {
        $scores = RetrievalMetrics::score([1, 2, 3], [2, 4], [1, 3, 5]);

        $this->assertSame(0.0, $scores['recall_at_1']);
        $this->assertSame(0.5, $scores['recall_at_3']);
        $this->assertSame(0.5, $scores['recall_at_5']);
        $this->assertSame(0.3333, round($scores['precision_at_3'], 4));
        $this->assertSame(0.5, $scores['mrr_at_5']);
        $this->assertSame(1.0, $scores['hit_at_5']);
        $this->assertSame(0.3869, round($scores['ndcg_at_5'], 4));
    }

    public function test_perfect_and_empty_relevance(): void
    {
        $perfect = RetrievalMetrics::score([7, 8], [7, 8]);
        $this->assertSame(0.5, $perfect['recall_at_1']);
        $this->assertSame(1.0, $perfect['ndcg_at_5']);

        $empty = RetrievalMetrics::score([7], []);
        $this->assertNull($empty['recall_at_5']);
        $this->assertNull($empty['mrr_at_5']);
    }

    public function test_aggregate_keeps_zero_values(): void
    {
        $agg = RetrievalMetrics::aggregate([
            ['recall_at_5' => 0.0],
            ['recall_at_5' => 1.0],
            ['recall_at_5' => null],
        ], ['recall_at_5']);

        $this->assertSame(0.5, $agg['recall_at_5']);
    }

    public function test_percentile_interpolates(): void
    {
        $this->assertSame(50.0, RetrievalMetrics::percentile([0, 100], 50));
        $this->assertSame(95.0, RetrievalMetrics::percentile([0, 100], 95));
        $this->assertNull(RetrievalMetrics::percentile([], 95));
    }

    public function test_judge_json_repair_and_kappa(): void
    {
        $this->assertSame(
            ['faithfulness' => 1],
            RagJudge::parseJson("```json\n{\"faithfulness\": 1,}\n```")
        );
        $this->assertNull(RagJudge::parseJson('no json here'));

        $this->assertSame(1.0, RagJudge::kappa([1, 0, 1], [1, 0, 1]));
        $this->assertLessThan(1.0, RagJudge::kappa([1, 0, 1, 0], [1, 1, 0, 0]));
    }
}
