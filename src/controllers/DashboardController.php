<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use DateTime;
use DateTimeInterface;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\web\Response;

/**
 * Aggregator for the Smart Search dashboard. Pulls daily series, top/trending
 * queries, and recent errors honoring the `range` (days) query param; index
 * coverage and budget consumption are range-independent. The heavy
 * coverage-by-site lookup is cached for 60s under a single key.
 */
class DashboardController extends Controller
{
    private const CACHE_TTL = 60;
    private const ALLOWED_RANGES = [7, 30, 90];
    private const DEFAULT_RANGE = 30;

    /** Days of real traffic needed before the burn rate is trustworthy enough to project an ETA. */
    private const MIN_DAYS_BUDGET_ETA = 7;
    /** An ETA further out than this isn't worth showing. */
    private const MAX_DAYS_BUDGET_ETA = 30;

    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $range = $this->resolveRange();
        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();
        $history = $plugin->historyService;
        $stats = $plugin->databaseService->getStatsSafe();

        $metrics = $this->loadMetrics($history, $range);
        $coverage = $this->loadCachedCoverage();
        $budget = $plugin->rateLimitService->getBudgetConsumption($metrics['sevenDayBurn']);
        $aggregates = $this->computeAggregates($metrics['dailySeries'], $range);
        $health = $this->buildHealth($settings, $stats, $budget);
        $coverageTotals = $this->sumCoverage($coverage);

        $recommendations = $plugin->recommendationsService->build([
            'dailySeries' => $metrics['dailySeries'],
            'coverage' => $coverage,
            'budget' => $budget,
            'cacheHitRate' => $history->getCacheHitRate($range),
            'zeroResultRate' => $history->getZeroResultRate($range),
            'totalEntries' => (int)($stats['entryCount'] ?? 0),
        ]);

        $sites = Craft::$app->getSites()->getAllSites();
        $siteCount = count($sites);
        $indexedSiteCount = count(array_filter(
            $coverage,
            static fn($c) => ($c['indexed'] ?? 0) > 0 && ($c['stale'] ?? 0) === 0 && ($c['notIndexed'] ?? 0) === 0
        ));

        $setupComplete = !empty($settings->getOpenaiApiKey())
            && (bool)($stats['isConnected'] ?? false)
            && (int)($stats['entryCount'] ?? 0) > 0;

        $user = Craft::$app->getUser()->getIdentity();
        $guideDismissed = $user
            ? (bool)($user->getPreference('smartSearchGuideDismissed') ?? false)
            : false;

        return $this->renderTemplate('smart-search/index', [
            'plugin' => $plugin,
            'settings' => $settings,
            'stats' => $stats,
            'range' => $range,
            'allowedRanges' => self::ALLOWED_RANGES,
            'seriesSearches' => $this->chartSeries($metrics['dailySeries'], 'searches'),
            'seriesCost' => $this->chartSeries($metrics['dailySeries'], 'cost'),
            'aggregates' => $aggregates,
            'coverage' => $coverage,
            'coverageTotals' => $coverageTotals['totals'],
            'coverageTotal' => $coverageTotals['total'],
            'coveragePct' => $coverageTotals['pct'],
            'budget' => $this->gateBudgetEta($budget, $metrics['daysWithData']),
            'topQueries' => $metrics['topQueries'],
            'trendingQueries' => $metrics['trendingQueries'],
            'recentErrors' => $metrics['recentErrors'],
            'recommendations' => $recommendations,
            'hasSearches' => $history->count() > 0,
            'siteCount' => $siteCount,
            'isMultisite' => $siteCount > 1,
            'indexedSiteCount' => $indexedSiteCount,
            'health' => $health,
            'setupComplete' => $setupComplete,
            'requiredSteps' => $this->buildRequiredSteps($settings, $stats),
            'recommendedSteps' => $this->buildRecommendedSteps($settings, $history, $stats),
            'guideDismissed' => $guideDismissed,
            'selectedSubnavItem' => 'dashboard',
        ]);
    }

    /**
     * AJAX: dismiss the post-setup recommended-steps guide card for the current user.
     */
    public function actionDismissGuide(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $user = Craft::$app->getUser()->getIdentity();
        if ($user) {
            Craft::$app->getUsers()->saveUserPreferences($user, ['smartSearchGuideDismissed' => true]);
        }

        return $this->asJson(['success' => true]);
    }

    private function resolveRange(): int
    {
        $range = (int)Craft::$app->getRequest()->getQueryParam('range', self::DEFAULT_RANGE);
        return in_array($range, self::ALLOWED_RANGES, true) ? $range : self::DEFAULT_RANGE;
    }

    private function loadMetrics($history, int $range): array
    {
        $dailySeries = $history->getDailySeries($range);
        // Fixed divisor: getDailySeries zero-fills, so /7.0 stays correct on fresh installs.
        $sevenDay = array_slice($dailySeries, -7);
        $sevenDayBurn = array_sum(array_column($sevenDay, 'cost')) / 7.0;
        $trendingWindow = min(14, max(3, (int)floor($range / 2)));

        return [
            'dailySeries' => $dailySeries,
            'topQueries' => $history->getTopKeywords($range, null, 10),
            'trendingQueries' => $history->getTrendingKeywords(null, $trendingWindow, 10),
            'recentErrors' => $history->getRecentErrors(10),
            'sevenDayBurn' => $sevenDayBurn,
            'daysWithData' => count(array_filter($dailySeries, static fn($r) => ($r['searches'] ?? 0) > 0)),
        ];
    }

    private function loadCachedCoverage(): array
    {
        return Craft::$app->getCache()->getOrSet(
            'smart_search_dash_coverage',
            fn() => SmartSearch::getInstance()->indexInspectionService->getCoverageBySite(),
            self::CACHE_TTL
        );
    }

    /**
     * Reshape a daily-series column into the { date, value } pairs the chart JS reads.
     */
    private function chartSeries(array $series, string $key): array
    {
        return array_map(
            static fn(array $row) => ['date' => $row['date'], 'value' => $row[$key]],
            $series
        );
    }

    /**
     * Roll the per-site coverage rows into the donut's totals, plus the headline percentage.
     * Keys match the series the donut chart expects.
     */
    private function sumCoverage(array $coverage): array
    {
        $totals = [
            'indexed' => (int)array_sum(array_column($coverage, 'indexed')),
            'stale' => (int)array_sum(array_column($coverage, 'stale')),
            'notIndexed' => (int)array_sum(array_column($coverage, 'notIndexed')),
        ];
        $total = array_sum($totals);

        return [
            'totals' => $totals,
            'total' => $total,
            'pct' => $total > 0 ? (int)round($totals['indexed'] / $total * 100) : 0,
        ];
    }

    /**
     * Blank the budget ETA unless it's both trustworthy and near enough to act on,
     * so the template can just ask whether there's an ETA to show.
     *
     * View-only: apply this to the copy handed to the template, never to the array
     * passed to RecommendationsService, which keys its own runaway-spend advisory
     * off etaDays and must still see a short ETA on a site with < 7 days of traffic.
     */
    private function gateBudgetEta(array $budget, int $daysWithData): array
    {
        $eta = $budget['etaDays'] ?? null;
        $worthShowing = $eta !== null
            && $eta < self::MAX_DAYS_BUDGET_ETA
            && $daysWithData >= self::MIN_DAYS_BUDGET_ETA;
        $budget['etaDays'] = $worthShowing ? $eta : null;

        return $budget;
    }

    /**
     * Roll up the daily series into headline KPI numbers and prior-period deltas.
     * Splits the window in half: most recent half vs prior half.
     */
    private function computeAggregates(array $series, int $rangeDays): array
    {
        $half = (int)ceil($rangeDays / 2);
        $recent = array_slice($series, -$half);
        $prior = array_slice($series, 0, max(0, count($series) - $half));

        $sum = static fn(array $rows, string $key) => array_sum(array_column($rows, $key));

        $searches = $sum($series, 'searches');
        $errors = $sum($series, 'errors');

        return [
            'rangeDays' => $rangeDays,
            'priorWindowDays' => $half,
            'searches' => $searches,
            'searchesDelta' => $this->pctDelta($sum($recent, 'searches'), $sum($prior, 'searches')),
            'cost' => round($sum($series, 'cost'), 6),
            'costDelta' => $this->pctDelta($sum($recent, 'cost'), $sum($prior, 'cost')),
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

    /**
     * Glanceable health signals for the top status strip.
     * Each item: { key, state: 'ok'|'warn'|'crit', label: string }.
     */
    private function buildHealth($settings, array $stats, array $budget): array
    {
        return [
            $this->apiHealth($settings, $stats),
            $this->indexerHealth(),
            $this->syncHealth($stats['lastIndexed'] ?? null),
            $this->budgetHealth($budget),
        ];
    }

    private function apiHealth($settings, array $stats): array
    {
        $ok = !empty($settings->getOpenaiApiKey()) && (bool)($stats['isConnected'] ?? false);
        return ['key' => 'api', 'state' => $ok ? 'ok' : 'crit', 'label' => $ok ? 'API connected' : 'API not connected'];
    }

    private function indexerHealth(): array
    {
        $pending = 0;
        try {
            foreach (Craft::$app->getQueue()->getJobInfo(100) as $info) {
                if (in_array($info['status'] ?? null, [1, 2], true)) {
                    $pending++;
                }
            }
        } catch (Throwable) {
            $pending = 0;
        }
        $label = $pending > 0
            ? $pending . ' indexer job' . ($pending === 1 ? '' : 's') . ' running'
            : 'Indexer idle';
        return ['key' => 'indexer', 'state' => 'ok', 'label' => $label];
    }

    private function syncHealth(mixed $lastIndexed): array
    {
        if (!$lastIndexed) {
            return ['key' => 'sync', 'state' => 'warn', 'label' => 'Never synced'];
        }
        try {
            $ts = $lastIndexed instanceof DateTimeInterface ? $lastIndexed : new DateTime((string)$lastIndexed);
        } catch (Throwable) {
            return ['key' => 'sync', 'state' => 'ok', 'label' => 'Last sync unknown'];
        }
        $ageHours = (time() - $ts->getTimestamp()) / 3600;
        return [
            'key' => 'sync',
            'state' => $ageHours > 72 ? 'warn' : 'ok',
            'label' => 'Last sync ' . Craft::$app->getFormatter()->asRelativeTime($ts),
        ];
    }

    private function budgetHealth(array $budget): array
    {
        $ratio = (float)($budget['ratio'] ?? 0);
        $state = $ratio >= 0.9 ? 'crit' : ($ratio >= 0.75 ? 'warn' : 'ok');
        $label = ($budget['cap'] ?? 0) > 0
            ? 'Budget ' . round($ratio * 100) . '% of cap'
            : 'No daily cap';
        return ['key' => 'budget', 'state' => $state, 'label' => $label];
    }

    private function buildRequiredSteps($settings, array $stats): array
    {
        $postgresConnected = (bool)($stats['isConnected'] ?? false);
        $hasOpenaiKey = !empty($settings->getOpenaiApiKey());

        return [
            [
                'done' => $postgresConnected,
                'label' => 'Connect a Postgres database with pgvector',
                'hint' => 'Smart Search keeps a vector embedding for every entry so it can compare meaning, not just words. We use Postgres with the pgvector extension because it scales to millions of rows and keeps the data on infrastructure you control.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/settings/connections/postgres'), 'label' => 'Setup Postgres'],
            ],
            [
                'done' => $hasOpenaiKey,
                'label' => 'Add your OpenAI API key',
                'hint' => 'Your entries are sent to OpenAI’s embedding model to turn each one into a vector, and to the chat model when AI Answer synthesises a reply. The key is read from your Craft config and never stored in the plugin database.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/settings/connections/openai'), 'label' => 'Setup Api Key'],
            ],
            [
                'done' => $postgresConnected && $hasOpenaiKey && (int)($stats['entryCount'] ?? 0) > 0,
                'disabled' => !($postgresConnected && $hasOpenaiKey),
                'label' => 'Run your first index',
                'hint' => 'This reads every published entry, generates an embedding, and writes it to Postgres. Once it finishes, semantic search and AI Answer are live on your front-end. New and updated entries are indexed automatically from then on.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/index'), 'label' => 'Open indexer'],
            ],
        ];
    }

    private function buildRecommendedSteps($settings, $history, array $stats): array
    {
        $aiAnswerUrl = UrlHelper::cpUrl('smart-search/settings/ai-answer');
        $customPrompt = (string)($settings->aiAnswerCustomPrompt ?? '');
        $hasIndex = (bool)($stats['isConnected'] ?? false) && (int)($stats['entryCount'] ?? 0) > 0;
        $steps = [];
        if ($hasIndex) {
            $steps[] = [
                'done' => $history->count() > 0,
                'label' => 'Try a search in the Preview',
                'hint' => 'Run a few real queries against your index to see how results rank, what the AI Answer sounds like, and how the dashboard fills in. This is also the fastest way to spot content gaps before visitors do.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/preview'), 'label' => 'Open Preview'],
            ];
        }
        return array_merge($steps, [
            [
                'done' => $customPrompt !== '',
                'label' => 'Customise the AI Answer system prompt',
                'hint' => 'By default the answer model is told to reply from your content in a neutral tone. Override the system prompt to match your brand voice, restrict what it can talk about, or add domain rules like always linking to the relevant product page.',
                'cta' => ['url' => $aiAnswerUrl, 'label' => 'Configure'],
            ],
            [
                'done' => abs(((float)($settings->costBudgetDailyGlobal ?? 0)) - 3.0) > 0.001,
                'label' => 'Set a daily AI Answer spend cap',
                'hint' => 'Every AI Answer call costs a few cents on the OpenAI side. The daily cap pauses synthesis once spending hits your limit, so a traffic spike or a misbehaving bot can’t drain your account overnight. Default is $3/day.',
                'cta' => ['url' => $aiAnswerUrl, 'label' => 'Set budget'],
            ],
        ]);
    }
}
