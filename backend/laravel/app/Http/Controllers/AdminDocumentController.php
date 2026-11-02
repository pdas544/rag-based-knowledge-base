<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\EvalRun;
use App\Services\LLM\ModelPricing;
use App\Services\Qdrant\QdrantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminDocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = Document::with('user:id,name,email')->orderByDesc('created_at');
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate(20));
    }

    public function approve(Request $request, Document $document, QdrantService $qdrant)
    {
        if (! in_array($document->status, ['pending', 'processing', 'awaiting_review', 'failed'])) {
            return response()->json(['message' => "Cannot approve from status [{$document->status}]."], 422);
        }

        $document->update(['status' => 'ready', 'error' => null]);
        $qdrant->setPayloadStatus($document->id, 'ready', $document->qdrant_collection);

        return response()->json(['document' => $document->fresh()]);
    }

    public function reject(Request $request, Document $document, QdrantService $qdrant)
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:1000']);

        if (! in_array($document->status, ['pending', 'processing', 'awaiting_review', 'failed'])) {
            return response()->json(['message' => "Cannot reject from status [{$document->status}]."], 422);
        }

        $document->update(['status' => 'rejected', 'error' => $validated['reason'] ?? null]);
        $qdrant->deleteByDocumentId($document->id, $document->qdrant_collection);

        return response()->json(['document' => $document->fresh()]);
    }

    public function stats()
    {
        // 3.3 Token usage aggregates (DB-backed; no log parsing)
        $byModel = Conversation::selectRaw('model, COALESCE(SUM(total_tokens),0) as tokens, COALESCE(SUM(message_count),0) as messages')
            ->groupBy('model')->get();
        $totalTokens = (int) $byModel->sum('tokens');
        $totalMessages = (int) $byModel->sum('messages');
        $latestEval = EvalRun::query()->latest('id')->first();
        $today = now()->toDateString();

        return response()->json([
            'total_documents' => Document::count(),
            'pending_reviews' => Document::where('status', 'awaiting_review')->count(),
            'processing' => Document::whereIn('status', ['pending', 'processing'])->count(),
            'vector_chunks' => DocumentChunk::count(),
            'ready_documents' => Document::where('status', 'ready')->count(),
            'total_tokens_used' => $totalTokens,
            'total_messages' => $totalMessages,
            'avg_tokens_per_message' => $totalMessages > 0 ? round($totalTokens / $totalMessages, 1) : 0,
            'estimated_cost_usd' => round($byModel->sum(fn ($r) => ModelPricing::estimateCost($r->model, (int) $r->tokens)), 4),
            'by_model' => $byModel,
            'eval' => $latestEval ? [
                'run_id' => $latestEval->run_id,
                'mode' => $latestEval->mode,
                'created_at' => $latestEval->created_at,
                'metrics' => $latestEval->metrics,
            ] : null,
            'llm_telemetry' => [
                'retries_today' => (int) Cache::get("llm:retries:{$today}", 0),
                'fallbacks_today' => (int) Cache::get("llm:fallbacks:{$today}", 0),
                'circuit_open_today' => (int) Cache::get("llm:circuit_open:{$today}", 0),
                'circuit_opened_today' => (int) Cache::get("llm:circuit_opened:{$today}", 0),
            ],
        ]);
    }
}
