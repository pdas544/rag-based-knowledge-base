<?php

namespace App\Services\Qdrant;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class QdrantService
{
    public function __construct(
        private string $host = 'http://qdrant:6333',
        private string $collection = 'knowledge_base',
        private int $vectorSize = 1536,
        private string $apiKey = '',
    ) {
        $this->host = rtrim($this->host, '/');
    }

    /**
     * Pending request with the optional API key (QDRANT__SERVICE__API_KEY)
     * required when Qdrant is not exposed only to the private network.
     */
    private function client(int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout);

        return $this->apiKey !== ''
            ? $request->withHeaders(['api-key' => $this->apiKey])
            : $request;
    }

    /**
     * Liveness probe for /api/health. Never throws.
     */
    public function ready(): bool
    {
        try {
            return $this->client(3)->get("{$this->host}/readyz")->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function createCollection(): bool
    {
        $response = $this->client(30)->put("{$this->host}/collections/{$this->collection}", [
            'vectors' => [
                'size' => $this->vectorSize,
                'distance' => 'Cosine',
            ],
        ]);

        return $response->successful();
    }

    /**
     * @param  array<int, array{vector: array<int,float>, payload: array<string,mixed>, id?: string}>  $points
     */
    public function upsert(array $points, ?string $collection = null): bool
    {
        $collection ??= $this->collection;

        $mapped = collect($points)->map(fn ($p) => [
            'id' => $p['id'] ?? (string) Str::uuid(),
            'vector' => $p['vector'],
            'payload' => $p['payload'] ?? [],
        ])->values()->all();

        $response = $this->client(60)->put("{$this->host}/collections/{$collection}/points", [
            'points' => $mapped,
        ]);

        return $response->successful();
    }

    /**
     * @return array<int, array{point_id: string, score: float, payload: array}>
     */
    public function search(array $vector, int $topK = 5, float $threshold = 0.72, array $must = []): array
    {
        $filter = [
            'must' => array_merge([
                ['key' => 'status', 'match' => ['value' => 'ready']],
            ], $must),
        ];

        $response = $this->client(30)->post("{$this->host}/collections/{$this->collection}/points/search", [
            'vector' => $vector,
            'limit' => $topK,
            'score_threshold' => $threshold,
            'filter' => $filter,
            'with_payload' => true,
        ]);

        // Missing collection (fresh install / wiped volume) means "no context",
        // not a chat-breaking error.
        if ($response->status() === 404) {
            return [];
        }

        $response->throw();

        return collect($response->json('result', []))
            ->map(fn ($hit) => [
                'point_id' => (string) ($hit['id'] ?? ''),
                'score' => (float) ($hit['score'] ?? 0),
                'payload' => $hit['payload'] ?? [],
            ])->values()->all();
    }

    public function deleteByDocumentId(int $documentId, ?string $collection = null): bool
    {
        $collection ??= $this->collection;

        $response = $this->client(30)->post("{$this->host}/collections/{$collection}/points/delete", [
            'filter' => [
                'must' => [
                    ['key' => 'document_id', 'match' => ['value' => $documentId]],
                ],
            ],
        ]);

        return $response->successful();
    }

    public function setPayloadStatus(int $documentId, string $status, ?string $collection = null): bool
    {
        $collection ??= $this->collection;

        $response = $this->client(30)->post("{$this->host}/collections/{$collection}/points/payload", [
            'payload' => ['status' => $status],
            'filter' => [
                'must' => [
                    ['key' => 'document_id', 'match' => ['value' => $documentId]],
                ],
            ],
        ]);

        return $response->successful();
    }
}
