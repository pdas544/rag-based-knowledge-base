<?php

namespace App\Services\LLM;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        private int $maxRetries = 3,
        private int $retryBackoffMs = 500,
        private int $timeout = 120,
        /** @var array<int, string> */
        private array $fallbackModels = [],
        private int $circuitThreshold = 5,
        private int $circuitTtl = 300,
    ) {}

    public function streamChat(array $messages, array $context = []): \Generator
    {
        $primary = (string) ($context['model'] ?? $this->chatModel);

        // No key (local test): deterministic stub so Phase 1 works without OpenRouter.
        if ($this->apiKey === '') {
            yield 'Hello from OpenRouter stub (set OPENROUTER_API_KEY for live model).';

            return;
        }

        $models = array_values(array_unique(array_filter(array_merge([$primary], $this->fallbackModels))));

        // Prefer healthy models; if every circuit is open, still probe the
        // primary once so a stale breaker cannot lock the app out forever.
        $candidates = array_values(array_filter($models, fn ($m) => ! $this->circuitOpen($m)));
        if (count($candidates) < count($models)) {
            $this->incrementCounter('circuit_open');
            Log::channel('llm')->warning('llm_circuit_open', [
                'open' => array_values(array_diff($models, $candidates)),
            ]);
        }
        if ($candidates === []) {
            $candidates = [$primary];
        }

        $emitted = false;
        $lastError = null;

        $previousModel = null;
        foreach ($candidates as $model) {
            if ($previousModel !== null) {
                $this->incrementCounter('fallbacks');
                Log::channel('llm')->warning('llm_fallback', ['from' => $previousModel, 'to' => $model]);
            }
            $previousModel = $model;

            for ($attempt = 1; $attempt <= max(1, $this->maxRetries); $attempt++) {
                try {
                    foreach ($this->streamOnce($messages, $model) as $delta) {
                        $emitted = true;
                        yield $delta;
                    }
                    $this->recordSuccess($model);

                    return;
                } catch (\Throwable $e) {
                    $lastError = $e;
                    $this->recordFailure($model);

                    // Once bytes reached the client we cannot safely retry
                    // without duplicating output.
                    if ($emitted) {
                        throw $e;
                    }

                    if ($attempt < max(1, $this->maxRetries)) {
                        $this->incrementCounter('retries');
                        Log::channel('llm')->info('llm_retry', [
                            'model' => $model,
                            'attempt' => $attempt,
                            'error' => mb_substr($e->getMessage(), 0, 200),
                        ]);
                        $this->sleepBackoff($attempt);
                    }
                }
            }
        }

        throw $lastError ?? new \RuntimeException('OpenRouter: no model available');
    }

    /**
     * Single streaming attempt for one model. Isolated so the caller can
     * retry/fall back when nothing has been emitted yet.
     */
    private function streamOnce(array $messages, string $model): \Generator
    {
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
            ->timeout($this->timeout)
            ->retry($this->maxRetries, $this->retryBackoffMs)
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
        return new Client(['timeout' => $this->timeout, 'stream' => true]);
    }

    private function circuitOpen(string $model): bool
    {
        return (int) Cache::get($this->circuitKey($model), 0) >= $this->circuitThreshold;
    }

    private function recordFailure(string $model): void
    {
        $key = $this->circuitKey($model);
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, $this->circuitTtl);

        if ($failures === $this->circuitThreshold) {
            $this->incrementCounter('circuit_opened');
            Log::channel('llm')->warning('llm_circuit_opened', [
                'model' => $model,
                'failures' => $failures,
                'ttl' => $this->circuitTtl,
            ]);
        }
    }

    private function incrementCounter(string $name): void
    {
        try {
            Cache::increment("llm:{$name}:".now()->toDateString());
        } catch (\Throwable) {
            // Telemetry must never break generation.
        }
    }

    private function recordSuccess(string $model): void
    {
        Cache::forget($this->circuitKey($model));
    }

    private function circuitKey(string $model): string
    {
        return 'llm:circuit:'.sha1($model);
    }

    private function sleepBackoff(int $attempt): void
    {
        $ms = $this->retryBackoffMs * (2 ** ($attempt - 1));
        usleep((int) (min($ms, 10000) * 1000));
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
