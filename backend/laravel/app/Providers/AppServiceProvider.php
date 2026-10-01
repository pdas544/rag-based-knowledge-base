<?php

namespace App\Providers;

use App\Services\LLM\EmbeddingProviderInterface;
use App\Services\LLM\LLMProviderInterface;
use App\Services\LLM\OpenRouterProvider;
use App\Services\Qdrant\QdrantService;
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
            embeddingModel: config('services.llm.embedding_model', 'openai/text-embedding-3-small'),
            appUrl: config('app.url'),
            appTitle: config('app.name'),
        ));

        $this->app->bind(LLMProviderInterface::class, OpenRouterProvider::class);
        $this->app->bind(EmbeddingProviderInterface::class, OpenRouterProvider::class);

        $this->app->singleton(QdrantService::class, function () {
            $raw = (string) config('services.qdrant.host', 'http://qdrant:6333');
            $port = (string) config('services.qdrant.port', '6333');
            $host = str_contains($raw, '://') ? $raw : "http://{$raw}:{$port}";

            return new QdrantService(
                host: $host,
                collection: config('services.qdrant.collection', 'knowledge_base'),
                vectorSize: (int) config('services.qdrant.vector_size', 1536),
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
