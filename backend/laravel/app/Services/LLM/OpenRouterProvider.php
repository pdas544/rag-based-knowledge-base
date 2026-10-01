<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;

class OpenRouterProvider implements EmbeddingProviderInterface, LLMProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://openrouter.ai/api/v1',
        private string $chatModel = 'openai/gpt-4o-mini',
        private string $embeddingModel = 'openai/text-embedding-3-small',
        private ?string $appUrl = null,
        private ?string $appTitle = null,
    ) {}

    public function streamChat(array $messages, array $context = []): \Generator
    {
        $model = $context['model'] ?? $this->chatModel;

        $response = Http::withHeaders($this->headers())
            ->timeout(120)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
            ]);

        $response->throw();

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');

        // Phase 0 scaffold: yield whole content as single chunk.
        // Phase 1 will switch to true SSE parsing.
        if ($content !== '') {
            yield $content;
        }
    }

    public function embed(string $text): array
    {
        $vectors = $this->embedBatch([$text]);

        return $vectors[0] ?? [];
    }

    public function embedBatch(array $texts): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(120)
            ->post("{$this->baseUrl}/embeddings", [
                'model' => $this->embeddingModel,
                'input' => array_values($texts),
            ]);

        $response->throw();

        return collect($response->json('data', []))
            ->sortBy('index')
            ->map(fn ($item) => $item['embedding'] ?? [])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ];

        if ($this->appUrl) {
            $headers['HTTP-Referer'] = $this->appUrl;
        }

        if ($this->appTitle) {
            $headers['X-Title'] = $this->appTitle;
        }

        return $headers;
    }
}
