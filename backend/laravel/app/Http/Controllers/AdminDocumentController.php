<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\Qdrant\QdrantService;
use Illuminate\Http\Request;

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
        return response()->json([
            'total_documents' => Document::count(),
            'pending_reviews' => Document::where('status', 'awaiting_review')->count(),
            'processing' => Document::whereIn('status', ['pending', 'processing'])->count(),
            'vector_chunks' => DocumentChunk::count(),
            'ready_documents' => Document::where('status', 'ready')->count(),
        ]);
    }
}
