<?php

namespace App\Services\RAG;

use App\Models\DocumentChunk;
use App\Services\LLM\LLMFactory;
use App\Services\LLM\OpenRouterProvider;
use App\Services\Qdrant\QdrantService;
use Illuminate\Support\Facades\Log;

class RagRetriever
{
    public function __construct(private QdrantService $qdrant) {}

    /**
     * Production retrieval path: embed -> Qdrant (threshold) -> keyword
     * fallback when the vector search returns nothing.
     */
    public function retrieve(
        string $query,
        ?int $topK = null,
        ?float $threshold = null,
        bool $allowFallback = true,
    ): RetrievalResult {
        $topK ??= (int) config('services.rag.top_k', 5);
        $threshold ??= (float) config('services.qdrant.score_threshold', 0.5);
        $started = microtime(true);

        // Stub mode: the chat provider can answer without a key, but
        // embeddings cannot. Keep the old controller semantics (no retrieval,
        // no deterministic refusal) when no key is configured.
        $embeddings = LLMFactory::embeddings();
        if ($embeddings instanceof OpenRouterProvider && config('services.llm.openrouter_api_key', '') === '') {
            return $this->result([], false, false, $started, $topK, $threshold);
        }

        try {
            $vector = $embeddings->embed($query);
            if ($vector === []) {
                return $this->result([], false, false, $started, $topK, $threshold);
            }

            $hits = $this->qdrant->search($vector, $topK, $threshold);
            $usedFallback = false;

            if ($hits === [] && $allowFallback) {
                $hits = $this->keywordFallback($query);
                $usedFallback = $hits !== [];
            }

            return $this->result($hits, true, $usedFallback, $started, $topK, $threshold);
        } catch (\Throwable $e) {
            Log::warning('RAG retrieval skipped', ['error' => $e->getMessage()]);

            return $this->result([], false, false, $started, $topK, $threshold, $e->getMessage());
        }
    }

    /**
     * Unthresholded ranking used by retrieval eval: never falls back, so
     * Recall/MRR/nDCG measure the vector ranker itself.
     */
    public function retrieveRanked(string $query, ?int $topK = null): RetrievalResult
    {
        return $this->retrieve($query, $topK, 0.0, false);
    }

    /**
     * Verbatim-term fallback over ready chunks when vector search finds
     * nothing (acronyms, names, existence questions). Cross-DB safe: plain
     * LIKE, no FULLTEXT (see AGENTS.md test-env notes).
     *
     * Candidates are RANKED, not position-ordered: each chunk scores by
     * distinct-term coverage weighted with inverse chunk-frequency, so a
     * rare term (dsa: 2 chunks) outranks a ubiquitous one (students: 30).
     * Candidate cap 200 fits prototype volumes; a BM25/FULLTEXT index is
     * the scaled replacement.
     *
     * @return array<int, array{point_id: string, score: null, payload: array<string, mixed>}>
     */
    public function keywordFallback(string $query, int $limit = 3): array
    {
        $stop = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can',
            'had', 'has', 'was', 'were', 'will', 'would', 'there', 'their', 'what',
            'when', 'where', 'which', 'while', 'with', 'from', 'that', 'this', 'how',
            'why', 'who', 'about', 'into', 'over', 'after', 'before', 'between',
            'under', 'again', 'once', 'here', 'than', 'then', 'them', 'they',
            'its', 'our', 'your', 'have', 'does', 'did', 'such', 'like',
            // Meta-query words ("any information regarding X") carry no content
            'information', 'regarding'];
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($query)) ?: [];
        $terms = array_values(array_unique(array_filter($words, function ($w) use ($stop) {
            // Strip LIKE wildcards instead of escaping (cross-DB safe)
            $w = str_replace(['%', '_', '\\'], '', $w);

            return strlen($w) >= 3 && ! in_array($w, $stop, true);
        })));
        if ($terms === []) {
            return [];
        }

        $candidates = DocumentChunk::whereHas('document', fn ($q) => $q->where('status', 'ready'))
            ->where(function ($q) use ($terms) {
                foreach ($terms as $t) {
                    $q->orWhere('content', 'LIKE', "%{$t}%");
                }
            })
            ->orderBy('document_id')->orderBy('chunk_index')
            ->limit(200)->with('document:id,filename')->get();
        if ($candidates->isEmpty()) {
            return [];
        }

        $freq = array_fill_keys($terms, 0);
        $lowered = [];
        foreach ($candidates as $c) {
            $text = mb_strtolower($c->content);
            $lowered[$c->id] = $text;
            foreach ($terms as $t) {
                if (str_contains($text, $t)) {
                    $freq[$t]++;
                }
            }
        }

        $ranked = $candidates->map(function ($c) use ($terms, $freq, $lowered) {
            $matched = array_filter($terms, fn ($t) => str_contains($lowered[$c->id], $t));
            $score = 0.0;
            foreach ($matched as $t) {
                $score += 1.0 / (1 + log(1 + $freq[$t]));
            }

            return ['chunk' => $c, 'score' => $score, 'coverage' => count($matched)];
        })->sort(function ($a, $b) {
            return [$b['score'], $b['coverage'], $a['chunk']->document_id, $a['chunk']->chunk_index]
                <=> [$a['score'], $a['coverage'], $b['chunk']->document_id, $b['chunk']->chunk_index];
        })->take($limit)->sortBy([['chunk.document_id', 'asc'], ['chunk.chunk_index', 'asc']])->values();

        return $ranked->map(fn ($r) => [
            'point_id' => $r['chunk']->qdrant_point_id ?? "db-{$r['chunk']->id}",
            'score' => null,
            'payload' => [
                'chunk_id' => $r['chunk']->id,
                'document_id' => $r['chunk']->document_id,
                'doc_title' => $r['chunk']->document->filename ?? 'doc',
                'chunk_index' => $r['chunk']->chunk_index,
                'text' => $r['chunk']->content,
            ],
        ])->all();
    }

    /**
     * @param  array<int, array{point_id: string, score: float|null, payload: array<string, mixed>}>  $hits
     */
    private function result(
        array $hits,
        bool $attempted,
        bool $usedFallback,
        float $started,
        int $topK,
        float $threshold,
        ?string $error = null,
    ): RetrievalResult {
        $topScore = $hits[0]['score'] ?? null;

        return new RetrievalResult(
            hits: $hits,
            attempted: $attempted,
            usedFallback: $usedFallback,
            topScore: $topScore !== null ? (float) $topScore : null,
            latencyMs: round((microtime(true) - $started) * 1000, 2),
            topK: $topK,
            threshold: $threshold,
            error: $error,
        );
    }
}
