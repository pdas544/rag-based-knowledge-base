<?php

namespace App\Services\LLM;

class LLMFactory
{
    public static function chat(): LLMProviderInterface
    {
        $driver = config('services.llm.driver', 'openrouter');

        return match ($driver) {
            // Future: 'ollama' => app(OllamaProvider::class),
            // Resolved via interface so tests can swap fakes.
            default => app(LLMProviderInterface::class),
        };
    }

    public static function embeddings(): EmbeddingProviderInterface
    {
        $driver = config('services.llm.driver', 'openrouter');

        return match ($driver) {
            default => app(EmbeddingProviderInterface::class),
        };
    }
}
