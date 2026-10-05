<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\User;
use App\Services\LLM\EmbeddingProviderInterface;
use App\Services\LLM\LLMProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FakeChatRAG implements LLMProviderInterface
{
    public static array $lastMessages = [];

    public function streamChat(array $messages, array $context = []): \Generator
    {
        self::$lastMessages = $messages;
        yield 'canned reply';
    }
}

class FakeEmbedRAG implements EmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        return array_fill(0, 1536, 0.1);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn () => array_fill(0, 1536, 0.1), $texts);
    }
}

class DocumentRAGTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeChatRAG::$lastMessages = [];
        Storage::fake('local');
        $this->swap(LLMProviderInterface::class, new FakeChatRAG);
        $this->swap(EmbeddingProviderInterface::class, new FakeEmbedRAG);
        // Specific patterns only: Http::fake() merges stubs, so a broad
        // '*/collections/*' would shadow the search stub below.
        Http::fake([
            '*/collections/knowledge_base' => Http::response(['result' => true], 200),
            '*/points' => Http::response(['result' => true], 200),
            '*/points/delete' => Http::response(['result' => true], 200),
            '*/points/payload' => Http::response(['result' => true], 200),
        ]);
        // config key gates embed/retrieval paths; fakes make it deterministic
        config(['services.llm.openrouter_api_key' => 'test-key']);
    }

    private function authHeaders(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function upload(User $user, string $name, string $content, string $mime = 'text/plain')
    {
        return $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent($name, $content, $mime),
        ], $this->authHeaders($user));
    }

    public function test_upload_runs_pipeline_to_awaiting_review(): void
    {
        $user = User::factory()->create();

        $res = $this->upload($user, 'notes.txt', str_repeat('RAG pipeline prose. ', 200));
        $res->assertCreated();
        $id = $res->json('document.id');

        // Sync queue: job already ran
        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'awaiting_review']);
        $doc = Document::find($id);
        $this->assertGreaterThan(0, $doc->chunk_count);
        $this->assertGreaterThan(0, $doc->chunks()->count());
        $this->assertNotNull($doc->chunks()->first()->qdrant_point_id);
    }

    public function test_duplicate_content_rejected_regardless_of_filename(): void
    {
        $user = User::factory()->create();
        $content = str_repeat('Same bytes here. ', 100);

        $this->upload($user, 'original.txt', $content)->assertCreated();
        $this->upload($user, 'renamed-copy.txt', $content)
            ->assertConflict()
            ->assertJsonStructure(['message', 'original_document_id']);
    }

    public function test_upload_validation_and_quota(): void
    {
        $user = User::factory()->create();
        $h = $this->authHeaders($user);

        $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('evil.exe', 100, 'application/octet-stream'),
        ], $h)->assertStatus(422);

        for ($i = 0; $i < 10; $i++) {
            $this->upload($user, "doc{$i}.txt", "Unique content number {$i}. ".str_repeat('pad ', 50))->assertCreated();
        }
        $this->upload($user, 'eleventh.txt', 'Over quota. '.str_repeat('pad ', 50))->assertStatus(429);
    }

    public function test_document_list_and_detail(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $id = $this->upload($user, 'mine.txt', str_repeat('My doc. ', 100))->assertCreated()->json('document.id');

        $this->getJson('/api/documents', $this->authHeaders($user))->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/documents/{$id}", $this->authHeaders($user))
            ->assertOk()->assertJsonStructure(['document' => ['id', 'status', 'chunks']]);
        $this->getJson("/api/documents/{$id}", $this->authHeaders($other))->assertForbidden();
    }

    public function test_admin_review_flow(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $id = $this->upload($user, 'review-me.txt', str_repeat('Awaiting review. ', 100))->assertCreated()->json('document.id');

        // Non-admin blocked
        $this->getJson('/api/admin/documents', $this->authHeaders($user))->assertForbidden();

        // Admin sees it in review queue
        $this->getJson('/api/admin/documents?status=awaiting_review', $this->authHeaders($admin))
            ->assertOk()->assertJsonPath('data.0.id', $id);

        // Approve -> ready
        $this->postJson("/api/admin/documents/{$id}/approve", [], $this->authHeaders($admin))
            ->assertOk()->assertJsonPath('document.status', 'ready');
        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'ready']);

        // Approve again -> 422
        $this->postJson("/api/admin/documents/{$id}/approve", [], $this->authHeaders($admin))->assertStatus(422);
    }

    public function test_admin_reject_flow(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $id = $this->upload($user, 'reject-me.txt', str_repeat('Reject this. ', 100))->assertCreated()->json('document.id');

        $this->postJson("/api/admin/documents/{$id}/reject", ['reason' => 'spam'], $this->authHeaders($admin))
            ->assertOk()->assertJsonPath('document.status', 'rejected');
        $this->assertDatabaseHas('documents', ['id' => $id, 'status' => 'rejected', 'error' => 'spam']);
    }

    public function test_retrieval_augments_chat_with_sources(): void
    {
        $user = User::factory()->create();
        $h = $this->authHeaders($user);

        $doc = Document::create([
            'user_id' => $user->id, 'filename' => 'seed.txt', 'mime' => 'text/plain',
            'size_bytes' => 10, 'sha256' => hash('sha256', 'seed'), 'path' => 'documents/seed.txt',
            'status' => 'ready', 'chunk_count' => 1,
        ]);

        Http::fake([
            '*/points/search' => Http::response(['result' => [[
                'id' => 'point-1', 'score' => 0.91,
                'payload' => ['document_id' => $doc->id, 'doc_title' => 'seed.txt', 'chunk_index' => 0, 'text' => 'seed context passage'],
            ]]], 200),
        ]);

        $convoId = $this->postJson('/api/conversations', [], $h)->assertCreated()->json('conversation.id');

        $stream = $this->postJson("/api/conversations/{$convoId}/messages", ['content' => 'What is in seed?'], $h);
        $stream->assertOk();

        $captured = '';
        ob_start(function ($chunk) use (&$captured) {
            $captured .= $chunk;

            return '';
        });
        $stream->baseResponse->sendContent();
        ob_end_clean();

        $this->assertStringContainsString('canned reply', $captured);
        $this->assertStringContainsString('seed context passage', json_encode(FakeChatRAG::$lastMessages));

        // Strict grounding: system prompt constrains to retrieved context only
        $system = collect(FakeChatRAG::$lastMessages)->firstWhere('role', 'system');
        $this->assertNotNull($system);
        $this->assertStringContainsString('Answer ONLY from the retrieved context', $system['content']);
        $this->assertStringContainsString('Retrieved knowledge-base context:', $system['content']);
        $this->assertStringContainsString('Do not add outside knowledge', $system['content']);

        $assistant = Message::where('conversation_id', $convoId)->where('role', 'assistant')->first();
        $this->assertNotNull($assistant);
        $this->assertSame('point-1', $assistant->sources[0]['point_id']);
        $this->assertSame($doc->id, $assistant->sources[0]['doc_id']);

        // Threshold wiring: search must carry the configured (Nemotron-calibrated) value
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/points/search')
            && abs((float) $request['score_threshold'] - (float) config('services.qdrant.score_threshold')) < 0.0001);

        $this->assertSame(2, Conversation::find($convoId)->message_count);
    }
}
