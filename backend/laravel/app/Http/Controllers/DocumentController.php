<?php

namespace App\Http\Controllers;

use App\Jobs\ParseChunkEmbedJob;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $docs = Document::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($docs);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,docx,jpeg,png,webp,txt,md|max:10240',
        ]);

        $file = $validated['file'];
        $sha256 = hash_file('sha256', $file->getRealPath());

        $existing = Document::where('sha256', $sha256)->first();
        if ($existing) {
            return response()->json([
                'message' => 'Duplicate document.',
                'original_document_id' => $existing->id,
            ], 409);
        }

        // 10 docs / 30 days (rejected uploads don't count)
        $recent = Document::where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->where('status', '!=', 'rejected')
            ->count();
        if ($recent >= 10 && ! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Monthly upload quota exceeded (10 docs/30d).'], 429);
        }

        $path = $file->storeAs(
            "documents/{$request->user()->id}",
            "{$sha256}_{$file->getClientOriginalName()}",
            'local'
        );

        $document = Document::create([
            'user_id' => $request->user()->id,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'sha256' => $sha256,
            'path' => $path,
            'status' => 'pending',
        ]);

        ParseChunkEmbedJob::dispatch($document->id)->onQueue('embed');

        return response()->json(['document' => $document], 201);
    }

    public function show(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'document' => $document->load(['chunks' => fn ($q) => $q->orderBy('chunk_index')]),
        ]);
    }

    public function destroy(Request $request, Document $document)
    {
        $this->authorize('delete', $document);

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json(null, 204);
    }
}
