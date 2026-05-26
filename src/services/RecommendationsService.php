<?php

namespace ghoststreet\craftsmartsearch\services;

use craft\helpers\UrlHelper;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\base\Component;

/**
 * Produces actionable advisories for the dashboard, derived from history
 * series, index coverage, and current budget/cache state. Pure read-only.
 *
 * Each advisory is shaped as:
 *   ['level' => 'info'|'warn'|'crit', 'title' => string, 'body' => string, 'cta' => ['label' => string, 'url' => string]|null]
 */
class RecommendationsService extends Component
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_CRIT = 'crit';

    /**
     * @param array $context  Pre-computed values the dashboard already has, to avoid duplicate work.
     *                        Keys: dailySeries (list), coverage (list per-site), budget (assoc),
     *                        cacheHitRate (?float), zeroResultRate (?float), totalEntries (int).
     * @return list<array{level: string, title: string, body: string, cta: ?array{label: string, url: string}}>
     */
    public function build(array $context): array
    {
        return array_merge(
            $this->budgetAdvisories($context['budget'] ?? null),
            $this->coverageAdvisories($context['coverage'] ?? []),
            $this->cacheAdvisories($context['cacheHitRate'] ?? null, $context['dailySeries'] ?? []),
            $this->zeroResultAdvisories($context['zeroResultRate'] ?? null),
            $this->trafficAdvisories($context['dailySeries'] ?? [], (int)($context['totalEntries'] ?? 0)),
            $this->configurationAdvisories()
        );
    }

    private function budgetAdvisories(?array $budget): array
    {
        if (!is_array($budget) || (float)($budget['cap'] ?? 0) <= 0) {
            return [];
        }

        $ratio = (float)$budget['ratio'];
        if ($ratio >= 0.9) {
            return [$this->advisory(
                self::LEVEL_CRIT,
                'Daily cost budget at ' . round($ratio * 100) . '%',
                sprintf('$%.4f of $%.2f spent today. New AI Answer requests will be rejected at 100%%.', $budget['spent'], $budget['cap']),
                'Adjust budget',
                'smart-search/settings#budgets'
            )];
        }
        if ($ratio >= 0.75) {
            return [$this->advisory(
                self::LEVEL_WARN,
                'Daily cost budget at ' . round($ratio * 100) . '%',
                sprintf('$%.4f of $%.2f spent today.', $budget['spent'], $budget['cap']),
                'Adjust budget',
                'smart-search/settings#budgets'
            )];
        }
        if (!empty($budget['etaDays']) && $budget['etaDays'] < 7) {
            return [$this->advisory(
                self::LEVEL_INFO,
                'Projected to hit cap in ' . $budget['etaDays'] . ' days',
                'Based on 7-day burn rate. Raise the daily cap or reduce AI Answer traffic if this is unexpected.',
                'Adjust budget',
                'smart-search/settings#budgets'
            )];
        }
        return [];
    }

    private function coverageAdvisories(array $coverage): array
    {
        $stale = 0;
        $notIndexed = 0;
        $total = 0;
        foreach ($coverage as $c) {
            $stale += (int)($c['stale'] ?? 0);
            $notIndexed += (int)($c['notIndexed'] ?? 0);
            $total += (int)($c['total'] ?? 0);
        }
        if ($total === 0) {
            return [];
        }

        $out = [];
        if ($stale / $total > 0.05) {
            $out[] = $this->advisory(
                self::LEVEL_WARN,
                "{$stale} entries need reindex",
                'Entries have changed since their vectors were generated. Run a sync to refresh.',
                'Open Index sync',
                'smart-search/index'
            );
        }
        if ($notIndexed > 0 && $notIndexed / $total > 0.1) {
            $out[] = $this->advisory(
                self::LEVEL_INFO,
                "{$notIndexed} entries not yet indexed",
                'These entries are in your indexable sections but have no stored vectors.',
                'View entries',
                'smart-search/index'
            );
        }
        return $out;
    }

    private function cacheAdvisories(?float $cacheHitRate, array $dailySeries): array
    {
        if ($cacheHitRate === null || $cacheHitRate >= 0.15 || empty($dailySeries)) {
            return [];
        }
        $totalSearches = array_sum(array_column($dailySeries, 'searches'));
        if ($totalSearches <= 50) {
            return [];
        }
        return [$this->advisory(
            self::LEVEL_INFO,
            'Low embedding cache hit rate',
            'Only ' . round($cacheHitRate * 100) . '% of queries hit the cache. Consider raising the cache TTL to reduce embedding cost.',
            'Tune cache',
            'smart-search/settings#cache'
        )];
    }

    private function zeroResultAdvisories(?float $zeroResultRate): array
    {
        if ($zeroResultRate === null || $zeroResultRate <= 0.4) {
            return [];
        }
        return [$this->advisory(
            self::LEVEL_WARN,
            round($zeroResultRate * 100) . '% of recent searches return zero results',
            'Inspect top zero-result queries: they often indicate missing content or overly strict thresholds.',
            'View zero-results',
            'smart-search/insights?tab=zero-results'
        )];
    }

    private function trafficAdvisories(array $dailySeries, int $totalEntries): array
    {
        if (empty($dailySeries) || $totalEntries <= 0) {
            return [];
        }

        $out = [];
        $sevenDay = array_slice($dailySeries, -7);
        if (array_sum(array_column($sevenDay, 'searches')) === 0) {
            $out[] = $this->advisory(
                self::LEVEL_INFO,
                'No searches recorded in the last 7 days',
                'Content is indexed but the search endpoint is not receiving traffic. Verify your front-end integration.',
                null,
                null
            );
        }

        $errors = array_sum(array_column($dailySeries, 'errors'));
        $searches = array_sum(array_column($dailySeries, 'searches'));
        if ($searches > 100 && $errors / $searches > 0.1) {
            $out[] = $this->advisory(
                self::LEVEL_CRIT,
                round($errors / $searches * 100) . '% error rate',
                "{$errors} of {$searches} recent searches errored. Check the History errors view.",
                'View errors',
                'smart-search/insights?tab=history&errorsOnly=1'
            );
        }
        return $out;
    }

    private function configurationAdvisories(): array
    {
        if (!empty(SmartSearch::getInstance()->getSettings()->getOpenaiApiKey())) {
            return [];
        }
        return [$this->advisory(
            self::LEVEL_CRIT,
            'OpenAI API key is not configured',
            'Search and indexing will fail until a key is set.',
            'Open settings',
            'smart-search/settings'
        )];
    }

    private function advisory(string $level, string $title, string $body, ?string $ctaLabel, ?string $ctaUrl): array
    {
        return [
            'level' => $level,
            'title' => $title,
            'body' => $body,
            'cta' => ($ctaLabel && $ctaUrl) ? ['label' => $ctaLabel, 'url' => UrlHelper::cpUrl($ctaUrl)] : null,
        ];
    }
}
