<?php

namespace ghoststreet\craftsmartsearch\helpers;

use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * What a search cost in USD, priced from the active provider's rates, and the labels the
 * CP shows those rates in. Every rate is USD per 1M tokens.
 */
final class PricingTable
{
    /**
     * Total USD cost of the request so far: embedding call + AI Answer/LLM call, from what
     * UsageTracker has accumulated.
     *
     * Where the provider reported what it charged for the embedding call, that figure is
     * used in place of the table's estimate for that term only. The answer call has no
     * equivalent: the SDK's usage object keeps the five OpenAI keys and drops anything a
     * gateway adds, so a reported cost never reaches us there. Substituting the embedding
     * figure for the whole total would therefore drop the answer spend entirely.
     *
     * Only a positive figure counts. A gateway serving a model on the site's own upstream
     * key (OpenRouter calls this BYOK) truthfully reports charging nothing, and taking that
     * at face value would price the call at zero and leave costBudgetDailyGlobal unable to
     * trip. The spend is real, it is just billed elsewhere, so the table prices it instead.
     */
    public static function costForUsage(): float
    {
        $usage = UsageTracker::snapshot();
        $reported = $usage['embeddingCost'];

        $embedding = $reported !== null && $reported > 0.0
            ? $reported
            : self::calculateCost($usage['embeddingModel'], $usage['embeddingTokens'], 0);

        $cost = $embedding + self::calculateCost($usage['aiAnswerModel'], $usage['aiAnswerInputTokens'], $usage['aiAnswerOutputTokens']);

        return round($cost, 6);
    }

    /** "Name ($0.02 per 1M tokens)", or a free label when it costs nothing. */
    public static function inputPriceLabel(string $name, float $input): string
    {
        if ($input <= 0.0) {
            return self::freeLabel($name);
        }

        return sprintf('%s ($%s per 1M tokens)', $name, self::money($input));
    }

    /** "Name ($0.20 / $1.25 per 1M tokens)", or a free label when it costs nothing. */
    public static function inputOutputPriceLabel(string $name, float $input, float $output): string
    {
        if ($input <= 0.0 && $output <= 0.0) {
            return self::freeLabel($name);
        }

        return sprintf('%s ($%s / $%s per 1M tokens)', $name, self::money($input), self::money($output));
    }

    private static function calculateCost(?string $model, int $inputTokens, int $outputTokens): float
    {
        if ($model === null) {
            return 0.0;
        }

        $rates = SmartSearch::getInstance()->getSettings()->provider()->pricing($model);

        if ($rates === null) {
            Logger::warning("PricingTable: unknown model '{$model}', cost defaulted to 0");
            return 0.0;
        }

        $cost = ($inputTokens / 1_000_000) * $rates['input']
              + ($outputTokens / 1_000_000) * $rates['output'];

        return round($cost, 6);
    }

    /** OpenRouter already says so in the name of most free models. */
    private static function freeLabel(string $name): string
    {
        return str_ends_with($name, '(free)') ? $name : $name . ' (free)';
    }

    /** At least cents, up to four decimals: the cheapest models bill at fractions of a cent per 1M tokens. */
    private static function money(float $usd): string
    {
        [$whole, $fraction] = explode('.', rtrim(number_format($usd, 4, '.', ''), '0'));

        return $whole . '.' . str_pad($fraction, 2, '0');
    }
}
