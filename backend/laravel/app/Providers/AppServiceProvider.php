<?php

namespace App\Providers;

use App\Services\LLM\EmbeddingProviderInterface;
use App\Services\LLM\LLMProviderInterface;
use App\Services\LLM\OpenRouterProvider;
use App\Services\Qdrant\QdrantService;
use App\Services\Scanning\VirusScanner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OpenRouterProvider::class, fn () => new OpenRouterProvider(
            apiKey: config('services.llm.openrouter_api_key', ''),
            baseUrl: config('services.llm.openrouter_base_url', 'https://openrouter.ai/api/v1'),
            chatModel: config('services.llm.chat_model', 'openai/gpt-4o-mini'),
            embeddingModel: config('services.llm.embedding_model', 'nvidia/nemotron-3-embed-1b:free'),
            appUrl: config('app.url'),
            appTitle: config('app.name'),
            temperature: (float) config('services.llm.temperature', 0.2),
            maxRetries: (int) config('services.llm.max_retries', 3),
            retryBackoffMs: (int) config('services.llm.retry_backoff_ms', 500),
            timeout: (int) config('services.llm.timeout', 120),
            fallbackModels: (array) config('services.llm.fallback_models', []),
            circuitThreshold: (int) config('services.llm.circuit_threshold', 5),
            circuitTtl: (int) config('services.llm.circuit_ttl', 300),
        ));

        $this->app->bind(LLMProviderInterface::class, OpenRouterProvider::class);
        $this->app->bind(EmbeddingProviderInterface::class, OpenRouterProvider::class);

        $this->app->singleton(VirusScanner::class, fn () => new VirusScanner(
            enabled: (bool) config('services.clamav.enabled', false),
            binary: (string) config('services.clamav.binary', 'clamdscan'),
            timeout: (int) config('services.clamav.timeout', 60),
        ));

        $this->app->singleton(QdrantService::class, function () {
            $raw = (string) config('services.qdrant.host', 'http://qdrant:6333');
            $port = (string) config('services.qdrant.port', '6333');
            $host = str_contains($raw, '://') ? $raw : "http://{$raw}:{$port}";

            return new QdrantService(
                host: $host,
                collection: config('services.qdrant.collection', 'knowledge_base'),
                vectorSize: (int) config('services.qdrant.vector_size', 2048),
                apiKey: (string) config('services.qdrant.api_key', ''),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
