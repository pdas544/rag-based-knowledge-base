<?php

namespace Tests\Feature;

use App\Services\LLM\LLMProviderInterface;
use App\Services\RAG\RagJudge;
use App\Services\RAG\RagRetriever;
use App\Services\RAG\RetrievalResult;
use Tests\TestCase;

class FakeEvalChat implements LLMProviderInterface
{
    public function streamChat(array $messages, array $context = []): \Generator
    {
        $system = (string) ($messages[0]['content'] ?? '');
        preg_match('/ch(\d+)/', $system, $matches);
        $chunk = $matches[1] ?? '1';

        yield "Grounded answer [policy.pdf ch{$chunk}].";
    }
}

class RagEvaluationTest extends TestCase
{
    private string $dataset = 'tests/Fixtures/eval_smoke.jsonl';

    protected function setUp(): void
    {
        parent::setUp();

        $this->swap(LLMProviderInterface::class, new FakeEvalChat);

        $chunkIndex = [101 => 1, 102 => 2, 103 => 3, 999 => 9];
        $result = function (array $ids, bool $fallback = false) use ($chunkIndex): RetrievalResult {
            $hits = array_map(fn ($id) => [
                'point_id' => "point-{$id}",
                'score' => $id === 999 ? 0.2 : 0.9,
                'payload' => [
                    'chunk_id' => $id,
                    'document_id' => 1,
                    'doc_title' => 'policy.pdf',
                    'chunk_index' => $chunkIndex[$id] ?? $id,
                    'text' => "context {$id}",
                ],
            ], $ids);

            return new RetrievalResult(
                hits: $hits,
                attempted: true,
                usedFallback: $fallback,
                topScore: $hits[0]['score'] ?? null,
                latencyMs: 5.0,
                topK: 5,
                threshold: 0.5,
            );
        };

        $retriever = $this->mock(RagRetriever::class);
        $retriever->shouldReceive('retrieveRanked')->andReturnUsing(function (string $q) use ($result) {
            return match (true) {
                str_contains($q, 'DSA') => $result([101, 999, 102]),
                str_contains($q, 'budget') => $result([103]),
                default => $result([]),
            };
        });
        $retriever->shouldReceive('retrieve')->andReturnUsing(function (string $q) use ($result) {
            return match (true) {
                str_contains($q, 'DSA') => $result([101]),
                str_contains($q, 'budget') => $result([103]),
                default => $result([]),
            };
        });

        $judge = $this->mock(RagJudge::class);
        $judge->shouldReceive('model')->andReturn('fake-judge');
        $judge->shouldReceive('judge')->andReturn([
            'faithfulness' => 0.9,
            'answer_relevancy' => 1.0,
            'answer_correctness' => 1.0,
            'context_precision' => 1.0,
            'context_recall' => 1.0,
            'refusal' => false,
            'reasoning' => 'test',
            'judge_model' => 'fake-judge',
            'judge_version' => 'test',
            'error' => null,
        ]);
        $this->swap(RagJudge::class, $judge);
    }

    public function test_full_eval_computes_metrics_and_freezes_baseline(): void
    {
        $baseline = sys_get_temp_dir().'/rag_eval_'.uniqid().'.json';

        $this->artisan('rag:evaluate', [
            '--mode' => 'full',
            '--dataset' => $this->dataset,
            '--accept-baseline' => true,
            '--baseline-path' => $baseline,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $json = json_decode((string) file_get_contents($baseline), true);
        $this->assertEquals(1.0, $json['metrics']['retrieval_ranking']['recall_at_5']);
        $this->assertEquals(0.75, $json['metrics']['retrieval_production']['recall_at_5']);
        $this->assertEquals(0.9, $json['metrics']['generation']['faithfulness']);
        $this->assertEquals(1.0, $json['metrics']['generation']['citation_accuracy']);
        $this->assertEquals(1.0, $json['metrics']['generation']['refusal_accuracy']);
        $this->assertEquals(0.0, $json['metrics']['generation']['false_answer_rate']);

        @unlink($baseline);
    }

    public function test_gate_fails_below_configured_threshold(): void
    {
        config(['services.rag.min_recall_at_5' => 0.9]);

        $this->artisan('rag:evaluate', [
            '--mode' => 'retrieval',
            '--dataset' => $this->dataset,
            '--dry-run' => true,
        ])->assertExitCode(1);
    }

    public function test_dataset_hash_mismatch_refuses_baseline_comparison(): void
    {
        $baseline = sys_get_temp_dir().'/rag_eval_'.uniqid().'.json';
        file_put_contents($baseline, json_encode([
            'dataset_hash' => 'deadbeef',
            'metrics' => [],
        ]));

        $this->artisan('rag:evaluate', [
            '--mode' => 'retrieval',
            '--dataset' => $this->dataset,
            '--baseline' => $baseline,
            '--dry-run' => true,
        ])->assertExitCode(2);

        @unlink($baseline);
    }
}
