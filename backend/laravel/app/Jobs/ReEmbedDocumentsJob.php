<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\LLM\LLMFactory;
use App\Services\Qdrant\QdrantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 3.8 Re-embed chunks after switching embedding models (e.g. OpenRouter ->
 * local Ollama `nomic-embed-text`, 1536/2048 -> 768).
 *
 * IMPORTANT: Qdrant collections are dimension-locked. Point QDRANT_COLLECTION
 * at a FRESH collection name (matching the new EMBEDDING_DIM) before running,
 * otherwise upserts fail on dimension mismatch.
 */
class ReEmbedDocumentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public ?int $documentId = null) {}

    public function handle(QdrantService $qdrant): void
    {
        $query = Document::whereIn('status', ['awaiting_review', 'ready']);
        if ($this->documentId) {
            $query->where('id', $this->documentId);
        }

        $qdrant->createCollection();

        foreach ($query->cursor() as $document) {
            $chunks = $document->chunks()->orderBy('chunk_index')->get();
            if ($chunks->isEmpty()) {
                continue;
            }

            try {
                $vectors = LLMFactory::embeddings()->embedBatch($chunks->pluck('content')->all());
                $points = [];
                foreach ($chunks as $i => $chunk) {
                    $id = $chunk->qdrant_point_id ?? (string) Str::uuid();
                    $points[] = [
                        'id' => $id,
                        'vector' => $vectors[$i] ?? [],
                        'payload' => [
                            'document_id' => $document->id,
                            'owner_user_id' => $document->user_id,
                            'chunk_index' => $chunk->chunk_index,
                            'text' => $chunk->content,
                            'doc_title' => $document->filename,
                            'mime' => $document->mime,
                            'created_at' => now()->toDateTimeString(),
                            'status' => $document->status,
                            'is_global' => true,
                        ],
                    ];
                    $chunk->update(['qdrant_point_id' => $id]);
                }
                $qdrant->upsert($points, $document->qdrant_collection);
                Log::channel('llm')->info('reembed_done', ['document_id' => $document->id, 'chunks' => count($points)]);
            } catch (\Throwable $e) {
                Log::channel('llm')->warning('reembed_failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
