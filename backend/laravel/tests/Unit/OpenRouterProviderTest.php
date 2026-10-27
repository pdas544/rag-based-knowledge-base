<?php

namespace Tests\Unit;

use App\Services\LLM\OpenRouterProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class TestableOpenRouterProvider extends OpenRouterProvider
{
    public static ?Client $client = null;

    protected function newHttpClient(): Client
    {
        return self::$client ?? parent::newHttpClient();
    }
}

class OpenRouterProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        TestableOpenRouterProvider::$client = null;
        parent::tearDown();
    }

    private function providerWithBody(string $sseBody, int $attempts = 3): TestableOpenRouterProvider
    {
        // Distinct Response objects: a shared instance has a single body
        // stream that is consumed after the first attempt.
        $responses = array_map(fn () => new Response(200, [], $sseBody), range(1, max(1, $attempts)));
        $mock = new MockHandler($responses);
        TestableOpenRouterProvider::$client = new Client(['handler' => HandlerStack::create($mock)]);

        return new TestableOpenRouterProvider(
            apiKey: 'test-key',
            maxRetries: $attempts,
            retryBackoffMs: 0,
        );
    }

    public function test_temperature_defaults_to_configured_value(): void
    {
        config(['services.llm.temperature' => 0.2]);

        $provider = $this->app->make(OpenRouterProvider::class);

        $prop = new \ReflectionProperty(OpenRouterProvider::class, 'temperature');
        $this->assertSame(0.2, $prop->getValue($provider));
    }

    public function test_stub_mode_needs_no_key(): void
    {
        $provider = new OpenRouterProvider(apiKey: '');

        $chunks = iterator_to_array($provider->streamChat([['role' => 'user', 'content' => 'hi']]));

        $this->assertNotEmpty($chunks);
        $this->assertStringContainsString('stub', $chunks[0]);
    }

    public function test_content_deltas_are_yielded(): void
    {
        $provider = $this->providerWithBody(
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\" there\"}}]}\n\n"
            ."data: [DONE]\n\n"
        );

        $this->assertSame(['Hi', ' there'], iterator_to_array($provider->streamChat([])));
    }

    public function test_provider_error_chunk_throws_instead_of_silent_empty(): void
    {
        $provider = $this->providerWithBody(
            "data: {\"error\":{\"message\":\"Rate limit exceeded\",\"code\":429}}\n\n"
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenRouter: Rate limit exceeded/');

        iterator_to_array($provider->streamChat([]));
    }

    public function test_transient_error_is_retried_before_succeeding(): void
    {
        $mock = new MockHandler([
            new Response(200, [], "data: {\"error\":{\"message\":\"Service temporarily overloaded\",\"code\":503}}\n\n"),
            new Response(200, [], "data: {\"choices\":[{\"delta\":{\"content\":\"Recovered\"}}]}\n\n"),
            new Response(200, [], "data: [DONE]\n\n"),
        ]);
        TestableOpenRouterProvider::$client = new Client(['handler' => HandlerStack::create($mock)]);

        $provider = new TestableOpenRouterProvider(apiKey: 'test-key', maxRetries: 3, retryBackoffMs: 0);

        $this->assertSame(['Recovered'], iterator_to_array($provider->streamChat([])));
    }
}
