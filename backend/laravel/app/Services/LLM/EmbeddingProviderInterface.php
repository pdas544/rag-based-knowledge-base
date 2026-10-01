<?php

namespace App\Services\LLM;

interface EmbeddingProviderInterface
{
    /** @return array<int, float> */
    public function embed(string $text): array;

    /** @return array<int, array<int, float>> */
    public function embedBatch(array $texts): array;
}
