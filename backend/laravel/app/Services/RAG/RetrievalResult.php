<?php

namespace App\Services\RAG;

class RetrievalResult
{
    /**
     * @param  array<int, array{point_id: string, score: float|null, payload: array<string, mixed>}>  $hits
     */
    public function __construct(
        public readonly array $hits,
        public readonly bool $attempted,
        public readonly bool $usedFallback,
        public readonly ?float $topScore,
        public readonly float $latencyMs,
        public readonly int $topK,
        public readonly float $threshold,
        public readonly ?string $error = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    /**
     * Chat-message source descriptors (also persisted on messages.sources).
     *
     * @return array<int, array{point_id: string, doc_id: int|null, chunk_index: int|null, title: string|null, score: float|null}>
     */
    public function sources(): array
    {
        return array_map(fn ($hit) => [
            'point_id' => $hit['point_id'],
            'doc_id' => $hit['payload']['document_id'] ?? null,
            'chunk_index' => $hit['payload']['chunk_index'] ?? null,
            'title' => $hit['payload']['doc_title'] ?? null,
            'score' => $hit['score'],
        ], $this->hits);
    }

    /**
     * Retrieval telemetry shape logged alongside llm_call.
     *
     * @return array<string, mixed>
     */
    public function telemetry(): array
    {
        return [
            'attempted' => $this->attempted,
            'used_fallback' => $this->usedFallback,
            'hits' => count($this->hits),
            'top_score' => $this->topScore,
            'latency_ms' => $this->latencyMs,
            'top_k' => $this->topK,
            'threshold' => $this->threshold,
        ];
    }
}
