<?php

namespace Tests\Feature;

use App\Jobs\ParseChunkEmbedJob;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\User;
use App\Models\UserQuota;
use App\Services\LLM\EmbeddingProviderInterface;
use App\Services\LLM\LLMProviderInterface;
use App\Services\Scanning\InfectedFileException;
use App\Services\Scanning\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FakeChatPR implements LLMProviderInterface
{
    public function streamChat(array $messages, array $context = []): \Generator
    {
        yield 'grounded reply';
    }
}

class FakeEmbedPR implements EmbeddingProviderInterface
{
    public function embed(string $text): array
    {
        return array_fill(0, 2048, 0.1);
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn () => array_fill(0, 2048, 0.1), $texts);
    }
}

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->swap(LLMProviderInterface::class, new FakeChatPR);
        $this->swap(EmbeddingProviderInterface::class, new FakeEmbedPR);
    }

    private function authHeaders(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $user->createToken('test')->plainTextToken;

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_health_endpoint_reports_core_dependencies(): void
    {
        Http::fake(['*/readyz' => Http::response('', 200)]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonPath('checks.qdrant.ok', true);
    }

    public function test_sessions_table_migration_exists(): void
    {
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    public function test_prompt_quota_is_reserved_atomically_at_cap(): void
    {
        $user = User::factory()->create();
        $h = $this->authHeaders($user);
        $convo = Conversation::create(['user_id' => $user->id]);

        UserQuota::forToday($user->id)->update(['prompt_count' => 29]);

        $this->postJson("/api/conversations/{$convo->id}/messages", ['content' => 'last allowed'], $h)
            ->assertOk();

        $this->assertSame(30, UserQuota::forToday($user->id)->prompt_count);

        // Cap reached: the reservation must reject instead of overrunning.
        $this->postJson("/api/conversations/{$convo->id}/messages", ['content' => 'one too many'], $h)
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_qdrant_outage_does_not_break_streaming(): void
    {
        config(['services.llm.openrouter_api_key' => 'test-key']);
        Http::fake(['*/points/search' => Http::response(['message' => 'boom'], 500)]);

        $user = User::factory()->create();
        $h = $this->authHeaders($user);
        $convo = Conversation::create(['user_id' => $user->id]);

        $response = $this->postJson("/api/conversations/{$convo->id}/messages", ['content' => 'hello'], $h);
        $response->assertOk();

        $captured = '';
        ob_start(function ($chunk) use (&$captured) {
            $captured .= $chunk;

            return '';
        });
        $response->baseResponse->sendContent();
        ob_end_clean();

        $this->assertStringContainsString('grounded reply', $captured);
        $this->assertStringNotContainsString('"error"', $captured);
    }

    public function test_document_job_marks_missing_source_failed(): void
    {
        $user = User::factory()->create();
        $doc = Document::create([
            'user_id' => $user->id, 'filename' => 'gone.txt', 'mime' => 'text/plain',
            'size_bytes' => 10, 'sha256' => hash('sha256', 'gone'), 'path' => 'documents/gone.txt',
            'status' => 'pending',
        ]);

        try {
            $this->app->call([new ParseChunkEmbedJob($doc->id), 'handle']);
        } catch (\Throwable) {
            // Job rethrows after recording the failure (queue retry semantics).
        }

        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'status' => 'failed']);
        $this->assertNotEmpty($doc->fresh()->error);
    }

    public function test_virus_scanner_detects_infection_and_disabled_is_noop(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($file, 'dummy');

        $script = tempnam(sys_get_temp_dir(), 'clam');
        file_put_contents($script, "#!/bin/sh\necho 'Eicar-Test-Signature FOUND'\nexit 1\n");
        chmod($script, 0o755);

        try {
            (new VirusScanner(enabled: false))->scan($file);

            $this->expectException(InfectedFileException::class);
            (new VirusScanner(enabled: true, binary: $script, timeout: 5))->scan($file);
        } finally {
            @unlink($file);
            @unlink($script);
        }
    }

    public function test_prune_files_removes_rejected_and_orphans(): void
    {
        Storage::disk('local')->put('documents/1/rejected.txt', 'x');
        Storage::disk('local')->put('documents/9/orphan.txt', 'y');

        $user = User::factory()->create();
        $doc = Document::create([
            'user_id' => $user->id, 'filename' => 'rejected.txt', 'mime' => 'text/plain',
            'size_bytes' => 1, 'sha256' => hash('sha256', 'rej'), 'path' => 'documents/1/rejected.txt',
            'status' => 'rejected',
        ]);
        $doc->created_at = now()->subDays(40);
        $doc->save();

        $this->artisan('documents:prune-files', ['--days' => 30])->assertSuccessful();

        Storage::disk('local')->assertMissing('documents/1/rejected.txt');
        Storage::disk('local')->assertMissing('documents/9/orphan.txt');
        $this->assertDatabaseHas('documents', ['id' => $doc->id]);
    }

    public function test_prune_files_dry_run_keeps_files(): void
    {
        Storage::disk('local')->put('documents/9/orphan.txt', 'y');

        $this->artisan('documents:prune-files', ['--days' => 0, '--dry-run' => true])->assertSuccessful();

        Storage::disk('local')->assertExists('documents/9/orphan.txt');
    }
}
