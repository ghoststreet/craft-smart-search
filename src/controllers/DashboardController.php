<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\web\Controller;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\web\Response;

/**
 * Aggregator for the Smart Search dashboard. Pulls daily series, index coverage,
 * budget consumption, top/zero/trending/slow queries, recent errors, and
 * recommendations — all honoring a global `range` (days) query param. Heavy
 * coverage-by-site lookup is cached for 60s.
 *
 * NOTE: the whole plugin is admin-only today (subnav + every controller).
 * The Quality vs Advanced tab split is design intent for when per-permission
 * gating lands; for now both tabs share the admin gate.
 */
class DashboardController extends Controller
{
    private const CACHE_TTL = 60;
    private const ALLOWED_RANGES = [7, 30, 90];
    private const DEFAULT_RANGE = 30;
    private const ALLOWED_TABS = ['quality', 'advanced'];
    private const DEFAULT_TAB = 'quality';

    /** Minimum number of searches before a rate or qualitative label is meaningful. */
    private const MIN_N_RATE = 30;
    /** Minimum total before a period-over-period delta is meaningful. */
    private const MIN_N_DELTA = 50;
    /** Minimum days of recorded data before the budget ETA is meaningful. */
    private const MIN_DAYS_BUDGET_ETA = 7;

    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $range = (int)$request->getQueryParam('range', self::DEFAULT_RANGE);
        if (!in_array($range, self::ALLOWED_RANGES, true)) {
            $range = self::DEFAULT_RANGE;
        }
        $tab = (string)$request->getQueryParam('tab', self::DEFAULT_TAB);
        if (!in_array($tab, self::ALLOWED_TABS, true)) {
            $tab = self::DEFAULT_TAB;
        }

        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();
        $stats = $plugin->databaseService->getStatsSafe();

        $history = $plugin->historyService;
        $cache = Craft::$app->getCache();

        $dailySeries = $history->getDailySeries($range);
        $cacheHitRate = $history->getCacheHitRate($range);
        $zeroResultRate = $history->getZeroResultRate($range);
        $topQueries = $history->getTopKeywords($range, null, 10);
        $zeroResults = $history->getZeroResultQueries($range, null, 10);
        $trendingWindow = min(14, max(3, (int)floor($range / 2)));
        $trendingQueries = $history->getTrendingKeywords(null, $trendingWindow, 10);
        $slowQueries = $history->getSlowQueries($range, null, 10, 1500);
        $recentErrors = $history->getRecentErrors(10);
        $p95Duration = $history->getOverallPercentile($range, 0.95) ?? 0;

        $coverage = $cache->getOrSet(
            'smart_search_dash_coverage',
            fn() => $plugin->indexInspectionService->getCoverageBySite(),
            self::CACHE_TTL
        );

        $sevenDay = array_slice($dailySeries, -7);
        $sevenDayBurn = count($sevenDay) > 0
            ? array_sum(array_column($sevenDay, 'cost')) / count($sevenDay)
            : 0.0;
        $budget = $plugin->rateLimitService->getBudgetConsumption($sevenDayBurn);

        $daysWithData = count(array_filter($dailySeries, static fn($r) => ($r['searches'] ?? 0) > 0));

        $aggregates = $this->computeAggregates($dailySeries, $range, $p95Duration);

        $recommendations = $plugin->recommendationsService->build([
            'dailySeries' => $dailySeries,
            'coverage' => $coverage,
            'budget' => $budget,
            'cacheHitRate' => $cacheHitRate,
            'zeroResultRate' => $zeroResultRate,
            'totalEntries' => (int)($stats['entryCount'] ?? 0),
        ]);

        $setupComplete = !empty($settings->getOpenaiApiKey())
            && (bool)($stats['isConnected'] ?? false)
            && (int)($stats['entryCount'] ?? 0) > 0;

        return $this->renderTemplate('smart-search/index', [
            'plugin' => $plugin,
            'settings' => $settings,
            'stats' => $stats,
            'range' => $range,
            'allowedRanges' => self::ALLOWED_RANGES,
            'tab' => $tab,
            'trendingWindow' => $trendingWindow,
            'dailySeries' => $dailySeries,
            'aggregates' => $aggregates,
            'daysWithData' => $daysWithData,
            'minNRate' => self::MIN_N_RATE,
            'minNDelta' => self::MIN_N_DELTA,
            'minDaysBudgetEta' => self::MIN_DAYS_BUDGET_ETA,
            'dataAsOf' => new \DateTime(),
            'coverage' => $coverage,
            'budget' => $budget,
            'cacheHitRate' => $cacheHitRate,
            'zeroResultRate' => $zeroResultRate,
            'topQueries' => $topQueries,
            'zeroResults' => $zeroResults,
            'trendingQueries' => $trendingQueries,
            'slowQueries' => $slowQueries,
            'recentErrors' => $recentErrors,
            'recommendations' => $recommendations,
            'setupComplete' => $setupComplete,
            'selectedSubnavItem' => 'dashboard',
        ]);
    }

    /**
     * Roll up the daily series into headline KPI numbers and prior-period deltas.
     * Splits the window in half: most recent half vs prior half.
     */
    private function computeAggregates(array $series, int $rangeDays, int $p95Duration): array
    {
        $half = (int)ceil($rangeDays / 2);
        $recent = array_slice($series, -$half);
        $prior = array_slice($series, 0, max(0, count($series) - $half));

        $sum = static fn(array $rows, string $key) => array_sum(array_column($rows, $key));

        $recentSearches = $sum($recent, 'searches');
        $priorSearches = $sum($prior, 'searches');
        $recentCost = $sum($recent, 'cost');
        $priorCost = $sum($prior, 'cost');

        // weighted-average duration across the window
        $weightedAvgNum = 0; $weightedAvgDen = 0;
        foreach ($series as $r) {
            if (($r['searches'] ?? 0) > 0) {
                $weightedAvgNum += $r['avgMs'] * $r['searches'];
                $weightedAvgDen += $r['searches'];
            }
        }
        $avgDuration = $weightedAvgDen > 0 ? (int)round($weightedAvgNum / $weightedAvgDen) : 0;

        $errors = $sum($series, 'errors');
        $searches = $sum($series, 'searches');

        return [
            'rangeDays' => $rangeDays,
            'priorWindowDays' => $half,
            'searches' => $searches,
            'searchesDelta' => $this->pctDelta($recentSearches, $priorSearches),
            'cost' => round($sum($series, 'cost'), 2),
            'costDelta' => $this->pctDelta($recentCost, $priorCost),
            'avgDurationMs' => $avgDuration,
            'p95DurationMs' => $p95Duration,
            'errors' => $errors,
            'errorRate' => $searches > 0 ? round($errors / $searches, 4) : 0.0,
        ];
    }

    private function pctDelta(float|int $recent, float|int $prior): ?float
    {
        if ($prior <= 0) {
            return $recent > 0 ? null : 0.0;
        }
        return round((($recent - $prior) / $prior) * 100, 1);
    }
}
