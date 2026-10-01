<?php

namespace App\Services\LLM;

class LLMFactory
{
    public static function chat(): LLMProviderInterface
    {
        $driver = config('services.llm.driver', 'openrouter');

        return match ($driver) {
            // Future: 'ollama' => app(OllamaProvider::class),
            default => app(OpenRouterProvider::class),
        };
    }

    public static function embeddings(): EmbeddingProviderInterface
    {
        $driver = config('services.llm.driver', 'openrouter');

        return match ($driver) {
            default => app(OpenRouterProvider::class),
        };
    }
}
