<?php

namespace App\Console\Commands;

use App\Models\EvalResult;
use App\Models\EvalRun;
use App\Services\LLM\LLMFactory;
use App\Services\LLM\OpenRouterProvider;
use App\Services\RAG\EvalDataset;
use App\Services\RAG\RagEvaluator;
use App\Services\RAG\RagJudge;
use App\Services\RAG\RagPromptBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RagEvaluate extends Command
{
    protected $signature = 'rag:evaluate
        {--mode=retrieval : retrieval or full}
        {--dataset=eval/datasets/golden.jsonl : JSONL dataset path}
        {--baseline= : Baseline run_id or JSON file}
        {--accept-baseline : Write the current metrics as the new baseline}
        {--baseline-path=eval/baselines/current.json : Baseline output path}
        {--calibrate= : Optional human labels JSONL for judge calibration}
        {--limit=0 : Evaluate the first N cases (0 = all)}
        {--top-k= : Retrieval top_k (default from config)}
        {--threshold= : Production score threshold (default from config)}
        {--chat-model= : Override the chat model}
        {--judge-model= : Override the judge model}
        {--skip-judge : Skip the LLM judge (generation metrics limited)}
        {--dry-run : Compute and print without persisting DB/artifacts}
        {--no-persist : Alias for --dry-run}';

    protected $description = 'Offline RAG evaluation: retrieval IR metrics, grounded generation checks, regression gates';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['retrieval', 'full'], true)) {
            $this->error('--mode must be retrieval or full.');

            return self::INVALID;
        }

        $datasetPath = (string) $this->option('dataset');
        try {
            $cases = EvalDataset::load($datasetPath);
            $datasetHash = EvalDataset::hash($datasetPath);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $cases = array_slice($cases, 0, $limit);
        }

        $topK = $this->option('top-k') !== null ? (int) $this->option('top-k') : (int) config('services.rag.top_k', 5);
        $threshold = $this->option('threshold') !== null
            ? (float) $this->option('threshold')
            : (float) config('services.qdrant.score_threshold', 0.5);
        $chatModel = (string) ($this->option('chat-model') ?: config('services.llm.chat_model', 'openai/gpt-4o-mini'));
        $judgeModel = (string) ($this->option('judge-model') ?: config('services.rag.judge_model', ''));
        config(['services.rag.judge_model' => $judgeModel]);

        if ($mode === 'full') {
            $chat = LLMFactory::chat();
            if ($chat instanceof OpenRouterProvider && config('services.llm.openrouter_api_key', '') === '') {
                $this->error('Full evaluation needs OPENROUTER_API_KEY (retrieval mode works without it).');

                return self::INVALID;
            }
        }

        $startedAt = now();
        $this->info(sprintf('Running %s eval on %d case(s), top_k=%d threshold=%.2f', $mode, count($cases), $topK, $threshold));

        $evaluator = app(RagEvaluator::class);
        $result = $evaluator->run($cases, $mode, [
            'top_k' => $topK,
            'threshold' => $threshold,
            'chat_model' => $chatModel,
            'skip_judge' => (bool) $this->option('skip-judge'),
        ]);

        $metrics = $result['metrics'];
        $calibration = $this->calibration($result['cases']);
        if ($calibration !== null) {
            $metrics['calibration'] = $calibration;
        }

        $metadata = [
            'mode' => $mode,
            'dataset_path' => $datasetPath,
            'dataset_hash' => $datasetHash,
            'git_sha' => $this->gitSha(),
            'chat_model' => $chatModel,
            'embedding_model' => (string) config('services.llm.embedding_model', ''),
            'embedding_dim' => (int) config('services.llm.embedding_dim', 0),
            'collection' => (string) config('services.qdrant.collection', 'knowledge_base'),
            'prompt_version' => RagPromptBuilder::VERSION,
            'judge_model' => app(RagJudge::class)->model(),
            'judge_version' => RagJudge::VERSION,
            'top_k' => $topK,
            'threshold' => $threshold,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'php' => PHP_VERSION,
        ];

        $this->render($metrics, $mode);

        $gate = $this->gate($metrics, $mode);
        $baseline = $this->compareBaseline($metrics, $datasetHash);
        if ($baseline === null) {
            return self::INVALID;
        }

        if ($this->option('accept-baseline')) {
            $this->writeBaseline($metrics, $metadata, $datasetHash);
        }

        if (! $this->option('dry-run') && ! $this->option('no-persist')) {
            $this->persist($result['cases'], $metrics, $metadata, $datasetHash, $startedAt);
        }

        if ($gate !== []) {
            foreach ($gate as $failure) {
                $this->error('Gate failed: '.$failure);
            }

            return self::FAILURE;
        }

        $this->info('All configured gates passed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function render(array $metrics, string $mode): void
    {
        $rows = [];
        foreach ($this->flatten($metrics) as $key => $value) {
            $rows[] = [$key, is_float($value) ? number_format($value, 4) : $value];
        }
        $this->table(['Metric', 'Value'], $rows);

        if ($mode === 'retrieval') {
            $this->line('Retrieval-only run: generation metrics are not applicable.');
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<int, string>
     */
    private function gate(array $metrics, string $mode): array
    {
        $failures = [];
        $recall = $metrics['retrieval_production']['recall_at_5'] ?? null;
        $minRecall = (float) config('services.rag.min_recall_at_5', 0.70);
        if ($recall !== null && $recall < $minRecall) {
            $failures[] = sprintf('Recall@5 %.4f < %.4f', $recall, $minRecall);
        }

        if ($mode === 'full') {
            $faithfulness = $metrics['generation']['faithfulness'] ?? null;
            $minFaithfulness = (float) config('services.rag.min_faithfulness', 0.70);
            if ($faithfulness !== null && $faithfulness < $minFaithfulness) {
                $failures[] = sprintf('Faithfulness %.4f < %.4f', $faithfulness, $minFaithfulness);
            }

            $falseRate = $metrics['generation']['false_answer_rate'] ?? null;
            $maxFalse = (float) config('services.rag.max_false_answer_rate', 0.20);
            if ($falseRate !== null && $falseRate > $maxFalse) {
                $failures[] = sprintf('False-answer rate %.4f > %.4f', $falseRate, $maxFalse);
            }
        }

        $p95 = $mode === 'full'
            ? ($metrics['generation']['p95_latency_ms'] ?? null)
            : ($metrics['retrieval_production']['p95_latency_ms'] ?? null);
        $maxP95 = (float) config('services.rag.max_p95_ms', 30000);
        if ($p95 !== null && $p95 > $maxP95) {
            $failures[] = sprintf('p95 latency %.0fms > %.0fms', $p95, $maxP95);
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>|null
     */
    private function compareBaseline(array $metrics, string $datasetHash): ?array
    {
        $option = (string) $this->option('baseline');
        if ($option === '' || $this->option('accept-baseline')) {
            return [];
        }

        $baseline = $this->loadBaseline($option);
        if ($baseline === null) {
            $this->error("Baseline not found: {$option}");

            return null;
        }

        if (($baseline['dataset_hash'] ?? '') !== $datasetHash) {
            $this->error('Dataset hash changed. Re-label intentionally and freeze with --accept-baseline.');

            return null;
        }

        $current = $this->flatten($metrics);
        $previous = $this->flatten((array) ($baseline['metrics'] ?? []));
        $rows = [];
        foreach ($current as $key => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $old = $previous[$key] ?? null;
            $rows[] = [
                $key,
                is_numeric($old) ? number_format((float) $old, 4) : '-',
                number_format((float) $value, 4),
                is_numeric($old) ? number_format((float) $value - (float) $old, 4) : '-',
            ];
        }
        $this->table(['Metric', 'Baseline', 'Current', 'Delta'], $rows);

        return $baseline;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $metadata
     */
    private function writeBaseline(array $metrics, array $metadata, string $datasetHash): void
    {
        $path = (string) $this->option('baseline-path');
        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        file_put_contents($absolute, json_encode([
            'run_id' => null,
            'dataset_hash' => $datasetHash,
            'metrics' => $metrics,
            'metadata' => $metadata,
            'frozen_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("Baseline frozen at {$path}");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadBaseline(string $option): ?array
    {
        $absolute = str_starts_with($option, '/') ? $option : base_path($option);
        if (is_file($absolute)) {
            $decoded = json_decode((string) file_get_contents($absolute), true);

            return is_array($decoded) ? $decoded : null;
        }

        $run = EvalRun::where('run_id', $option)->first();

        return $run?->metrics !== null
            ? ['dataset_hash' => $run->dataset_hash, 'metrics' => $run->metrics, 'metadata' => $run->metadata]
            : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cases
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $metadata
     */
    private function persist(array $cases, array $metrics, array $metadata, string $datasetHash, \DateTimeInterface $startedAt): void
    {
        $runId = now()->format('Ymd_His').'_'.Str::lower(Str::random(6));
        $run = EvalRun::create([
            'run_id' => $runId,
            'mode' => $metadata['mode'],
            'status' => 'completed',
            'dataset_path' => $metadata['dataset_path'],
            'dataset_hash' => $datasetHash,
            'git_sha' => $metadata['git_sha'],
            'chat_model' => $metadata['chat_model'],
            'embedding_model' => $metadata['embedding_model'],
            'embedding_dim' => $metadata['embedding_dim'],
            'collection' => $metadata['collection'],
            'prompt_version' => $metadata['prompt_version'],
            'judge_model' => $metadata['judge_model'],
            'top_k' => $metadata['top_k'],
            'threshold' => $metadata['threshold'],
            'question_count' => count($cases),
            'metrics' => $metrics,
            'metadata' => $metadata,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);

        $dir = "eval/{$runId}";
        $disk = Storage::disk('local');
        $disk->makeDirectory($dir);

        $lines = [];
        foreach ($cases as $case) {
            EvalResult::create([
                'eval_run_id' => $run->id,
                'question_id' => $case['id'],
                'question' => $case['question'],
                'answerable' => $case['answerable'],
                'answer' => $case['answer'],
                'expected_answer' => $case['expected_answer'],
                'retrieved' => $case['retrieval'],
                'relevant_chunk_ids' => $case['relevant_chunk_ids'],
                'scores' => $case['scores'],
                'judge_raw' => $case['judge'],
                'latency_ms' => $case['latency_ms'],
                'tokens' => $case['tokens'],
                'cost_usd' => $case['cost_usd'],
            ]);
            $lines[] = json_encode($case, JSON_UNESCAPED_SLASHES);
        }

        $disk->put("{$dir}/summary.json", (string) json_encode([
            'run_id' => $runId,
            'dataset_hash' => $datasetHash,
            'metrics' => $metrics,
            'metadata' => $metadata,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $disk->put("{$dir}/results.jsonl", implode("\n", $lines)."\n");

        $this->info("Artifacts: storage/app/private/{$dir} (run {$runId})");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function calibration(array $cases): ?array
    {
        $path = (string) $this->option('calibrate');
        if ($path === '') {
            return null;
        }

        $absolute = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_file($absolute)) {
            $this->warn("Calibration labels not found: {$path}");

            return null;
        }

        $labels = [];
        foreach (file($absolute, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['id'])) {
                $labels[$row['id']] = $row;
            }
        }

        $humanFaith = [];
        $judgeFaith = [];
        $humanCorrect = [];
        $judgeCorrect = [];
        foreach ($cases as $case) {
            $label = $labels[$case['id']] ?? null;
            if ($label === null || ! isset($case['scores']['faithfulness'], $case['scores']['answer_correctness'])) {
                continue;
            }
            $humanFaith[] = (int) ($label['faithfulness'] ?? 0);
            $judgeFaith[] = $case['scores']['faithfulness'] >= 0.5 ? 1 : 0;
            $humanCorrect[] = (int) ($label['correctness'] ?? 0);
            $judgeCorrect[] = $case['scores']['answer_correctness'] >= 0.5 ? 1 : 0;
        }

        return [
            'labeled' => count($humanFaith),
            'faithfulness_kappa' => RagJudge::kappa($humanFaith, $judgeFaith),
            'correctness_kappa' => RagJudge::kappa($humanCorrect, $judgeCorrect),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flatten(array $metrics, string $prefix = ''): array
    {
        $out = [];
        foreach ($metrics as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $out += $this->flatten($value, $name);
            } elseif (is_numeric($value)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    private function gitSha(): ?string
    {
        $sha = trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse HEAD 2>/dev/null'));

        return $sha !== '' ? $sha : null;
    }
}
