<?php

namespace App\Services\LLM;

interface LLMProviderInterface
{
    /**
     * Stream chat completion deltas.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $context  e.g. ['model' => 'openai/gpt-4o-mini']
     * @return \Generator<string>
     */
    public function streamChat(array $messages, array $context = []): \Generator;
}
