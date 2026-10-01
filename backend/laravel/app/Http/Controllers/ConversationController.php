<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ZipArchive;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $cacheKey = "user:{$user->id}:conversations:list:page:".$request->get('page', 1);

        $data = Cache::remember($cacheKey, 300, function () use ($user) {
            return Conversation::where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->paginate(20);
        });

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $conversation = Conversation::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'] ?? null,
            'model' => config('services.llm.chat_model', 'openai/gpt-4o-mini'),
        ]);

        Cache::forget("user:{$request->user()->id}:conversations:list:page:1");

        return response()->json(['conversation' => $conversation], 201);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        return response()->json(['conversation' => $conversation->loadCount('messages')]);
    }

    public function updateTitle(Request $request, Conversation $conversation)
    {
        $this->authorize('update', $conversation);

        $validated = $request->validate(['title' => 'required|string|max:255']);
        $conversation->update(['title' => $validated['title']]);

        Cache::forget("user:{$request->user()->id}:conversations:list:page:1");

        return response()->json(['conversation' => $conversation]);
    }

    public function destroy(Request $request, Conversation $conversation)
    {
        $this->authorize('delete', $conversation);
        $conversation->delete();

        Cache::forget("user:{$request->user()->id}:conversations:list:page:1");

        return response()->json(null, 204);
    }

    public function export(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $messages = $conversation->messages()->orderBy('created_at')->get()
            ->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
                'model' => $m->model,
                'sources' => $m->sources,
                'created_at' => $m->created_at,
            ]);

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
            'exported_at' => now()->toIso8601String(),
        ]);
    }

    public function exportAll(Request $request)
    {
        $user = $request->user();
        $conversations = Conversation::with('messages')->where('user_id', $user->id)->get();

        $tmp = tempnam(sys_get_temp_dir(), 'convos').'.zip';
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($conversations as $c) {
            $zip->addFromString("conversation-{$c->id}.json", json_encode([
                'conversation' => $c,
                'messages' => $c->messages,
                'exported_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));
        }
        $zip->close();

        return response()->download($tmp, 'conversations-export.zip')->deleteFileAfterSend(true);
    }
}
