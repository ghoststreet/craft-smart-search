<?php

namespace ghoststreet\craftsmartsearch\helpers;

/**
 * Static OpenAI pricing table (USD per 1M tokens).
 * Update entries here when prices change.
 */
final class PricingTable
{
    /**
     * model => ['input' => $/1M tokens, 'output' => $/1M tokens]
     * Embedding models only use 'input'.
     */
    private const PRICES = [
        'text-embedding-3-small' => ['input' => 0.02, 'output' => 0.0],
        'text-embedding-3-large' => ['input' => 0.13, 'output' => 0.0],

        'gpt-5.4-nano' => ['input' => 0.05, 'output' => 0.40],
    ];

    public static function calculateCost(?string $model, int $inputTokens, int $outputTokens = 0): float
    {
        if ($model === null) {
            return 0.0;
        }

        if (!isset(self::PRICES[$model])) {
            Logger::warning("PricingTable: unknown model '{$model}', cost defaulted to 0");
            return 0.0;
        }

        $rates = self::PRICES[$model];
        $cost = ($inputTokens / 1_000_000) * $rates['input']
              + ($outputTokens / 1_000_000) * $rates['output'];

        return round($cost, 6);
    }
}
