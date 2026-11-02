<?php

namespace App\Services\LLM;

class ModelPricing
{
    /** Blended $/1M tokens; free-tier models cost nothing. */
    public static function blendedRate(?string $model): float
    {
        $model = (string) $model;
        if (str_ends_with($model, ':free')) {
            return 0.0;
        }

        return match (true) {
            str_contains($model, 'gpt-4o-mini') => 0.30,
            str_contains($model, 'gemini') && str_contains($model, 'flash') => 0.40,
            str_contains($model, 'claude-3.5-sonnet') => 3.0,
            default => 0.50,
        };
    }

    public static function estimateCost(?string $model, int $tokens): float
    {
        return round($tokens * self::blendedRate($model) / 1_000_000, 6);
    }
}
