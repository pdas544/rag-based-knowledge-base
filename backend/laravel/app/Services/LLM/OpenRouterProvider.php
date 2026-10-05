<?php

namespace App\Services\LLM;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;

class OpenRouterProvider implements EmbeddingProviderInterface, LLMProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://openrouter.ai/api/v1',
        private string $chatModel = 'openai/gpt-4o-mini',
        private string $embeddingModel = 'nvidia/nemotron-3-embed-1b:free',
        private ?string $appUrl = null,
        private ?string $appTitle = null,
        private float $temperature = 0.2,
    ) {}

    public function streamChat(array $messages, array $context = []): \Generator
    {
        $model = $context['model'] ?? $this->chatModel;

        // No key (local test): deterministic stub so Phase 1 works without OpenRouter.
        if ($this->apiKey === '') {
            yield 'Hello from OpenRouter stub (set OPENROUTER_API_KEY for live model).';

            return;
        }

        $client = $this->newHttpClient();
        $response = $client->post("{$this->baseUrl}/chat/completions", [
            'headers' => $this->headers(),
            'json' => ['model' => $model, 'messages' => $messages, 'stream' => true, 'temperature' => $this->temperature],
        ]);

        $body = $response->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if (! str_starts_with($line, 'data:')) {
                    continue;
                }
                $payload = trim(substr($line, 5));
                if ($payload === '[DONE]') {
                    return;
                }
                $json = json_decode($payload, true);
                // OpenRouter can deliver provider errors (e.g. free-tier
                // rate limits) as 200 SSE chunks — surface, don't swallow.
                if (isset($json['error'])) {
                    $message = is_array($json['error'])
                        ? ($json['error']['message'] ?? json_encode($json['error']))
                        : (string) $json['error'];
                    throw new \RuntimeException("OpenRouter: {$message}");
                }
                $delta = (string) data_get($json, 'choices.0.delta.content', '');
                if ($delta !== '') {
                    yield $delta;
                }
            }
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

    protected function newHttpClient(): Client
    {
        return new Client(['timeout' => 120, 'stream' => true]);
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
