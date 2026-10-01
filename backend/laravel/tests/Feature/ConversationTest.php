<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        // Sanctum guard memoizes the user per app instance; clear it so
        // each HTTP call re-authenticates from its own Bearer token.
        $this->app['auth']->forgetGuards();

        $token = $user->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_conversation_crud(): void
    {
        $user = User::factory()->create();
        $h = $this->authHeaders($user);

        $create = $this->postJson('/api/conversations', ['title' => 'Hello'], $h)->assertCreated();
        $id = $create->json('conversation.id');
        $this->assertNotNull($id);

        $this->getJson('/api/conversations', $h)->assertOk();
        $this->getJson("/api/conversations/{$id}", $h)->assertOk();
        $this->patchJson("/api/conversations/{$id}/title", ['title' => 'Renamed'], $h)
            ->assertOk()->assertJsonPath('conversation.title', 'Renamed');
        $this->deleteJson("/api/conversations/{$id}", $h)->assertNoContent();
        $this->assertSoftDeleted('conversations', ['id' => $id]);
    }

    public function test_message_stream_and_pagination_and_quotas(): void
    {
        $user = User::factory()->create();
        $h = $this->authHeaders($user);

        $id = $this->postJson('/api/conversations', [], $h)->assertCreated()->json('conversation.id');

        // Validation: empty rejected
        $this->postJson("/api/conversations/{$id}/messages", ['content' => ''], $h)->assertStatus(422);

        // Stream (stubbed OpenRouter when no API key).
        // StreamedResponse body only renders on send — capture it manually.
        $stream = $this->postJson("/api/conversations/{$id}/messages", ['content' => 'Hi there'], $h);
        $stream->assertOk();
        $stream->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        // Swallowing callback: closure's ob_flush() would otherwise
        // push chunks to PHPUnit's own buffer instead of ours.
        $captured = '';
        ob_start(function ($chunk) use (&$captured) {
            $captured .= $chunk;

            return '';
        });
        $stream->baseResponse->sendContent();
        ob_end_clean();
        $body = $captured;

        $this->assertStringContainsString('data:', $body);
        $this->assertStringContainsString('done', $body);
        $this->assertStringContainsString('Hello from OpenRouter stub', $body);

        // Auto-title applied
        $this->assertDatabaseHas('conversations', ['id' => $id, 'title' => 'Hi there']);

        // Pagination cursor
        $this->getJson("/api/conversations/{$id}/messages?limit=1", $h)
            ->assertOk()->assertJsonStructure(['data', 'next_cursor']);

        // Export + quotas endpoints
        $this->getJson("/api/conversations/{$id}/export", $h)->assertOk()
            ->assertJsonStructure(['conversation', 'messages', 'exported_at']);
        $this->getJson('/api/quotas', $h)->assertOk()
            ->assertJsonStructure(['prompts_used', 'prompts_limit', 'tokens_used']);
    }

    public function test_ownership_is_enforced(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $id = $this->postJson('/api/conversations', [], $this->authHeaders($a))->assertCreated()->json('conversation.id');
        $this->getJson("/api/conversations/{$id}", $this->authHeaders($b))->assertForbidden();
    }
}
