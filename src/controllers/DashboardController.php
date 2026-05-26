<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\web\Response;

/**
 * Aggregator for the Smart Search dashboard. Pulls daily series, index coverage,
 * budget consumption, top/trending queries, recent errors, and recommendations —
 * all honoring a global `range` (days) query param. Heavy coverage-by-site
 * lookup is cached for 60s.
 */
class DashboardController extends Controller
{
    private const CACHE_TTL = 60;
    private const ALLOWED_RANGES = [7, 30, 90];
    private const DEFAULT_RANGE = 30;

    private const MIN_N_RATE = 30;
    private const MIN_DAYS_BUDGET_ETA = 7;

    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $range = (int)$request->getQueryParam('range', self::DEFAULT_RANGE);
        if (!in_array($range, self::ALLOWED_RANGES, true)) {
            $range = self::DEFAULT_RANGE;
        }

        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();
        $stats = $plugin->databaseService->getStatsSafe();

        $history = $plugin->historyService;
        $cache = Craft::$app->getCache();

        $dailySeries = $history->getDailySeries($range);
        $topQueries = $history->getTopKeywords($range, null, 10);
        $trendingWindow = min(14, max(3, (int)floor($range / 2)));
        $trendingQueries = $history->getTrendingKeywords(null, $trendingWindow, 10);
        $recentErrors = $history->getRecentErrors(10);

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

        $aggregates = $this->computeAggregates($dailySeries, $range);

        $siteCount = count(Craft::$app->getSites()->getAllSites());
        $isMultisite = $siteCount > 1;
        $indexedSiteCount = 0;
        foreach ($coverage as $c) {
            if (($c['indexed'] ?? 0) > 0 && ($c['stale'] ?? 0) === 0 && ($c['notIndexed'] ?? 0) === 0) {
                $indexedSiteCount++;
            }
        }

        $health = $this->buildHealth($settings, $stats, $budget);

        $recommendations = $plugin->recommendationsService->build([
            'dailySeries' => $dailySeries,
            'coverage' => $coverage,
            'budget' => $budget,
            'cacheHitRate' => $history->getCacheHitRate($range),
            'zeroResultRate' => $history->getZeroResultRate($range),
            'totalEntries' => (int)($stats['entryCount'] ?? 0),
        ]);

        $setupComplete = !empty($settings->getOpenaiApiKey())
            && (bool)($stats['isConnected'] ?? false)
            && (int)($stats['entryCount'] ?? 0) > 0;

        $settingsBase = UrlHelper::cpUrl('smart-search/settings');
        $requiredSteps = [
            [
                'done' => (bool)($stats['isConnected'] ?? false),
                'label' => 'Connect a Postgres database with pgvector',
                'hint' => 'Smart Search keeps a vector embedding for every entry so it can compare meaning, not just words. We use Postgres with the pgvector extension because it scales to millions of rows and keeps the data on infrastructure you control.',
                'cta' => ['url' => $settingsBase . '#connection-postgres', 'label' => 'Setup Postgres'],
            ],
            [
                'done' => !empty($settings->getOpenaiApiKey()),
                'label' => 'Add your OpenAI API key',
                'hint' => 'Your entries are sent to OpenAI’s embedding model to turn each one into a vector, and to the chat model when AI Answer synthesises a reply. The key is read from your Craft config and never stored in the plugin database.',
                'cta' => ['url' => $settingsBase . '#connection-openai', 'label' => 'Setup Api Key'],
            ],
            [
                'done' => (bool)($stats['isConnected'] ?? false) && (int)($stats['entryCount'] ?? 0) > 0,
                'label' => 'Run your first index',
                'hint' => 'This reads every published entry, generates an embedding, and writes it to Postgres. Once it finishes, semantic search and AI Answer are live on your front-end. New and updated entries are indexed automatically from then on.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/index'), 'label' => 'Open indexer'],
            ],
        ];

        $customPrompt = (string)($settings->aiAnswerCustomPrompt ?? '');
        $recommendedSteps = [
            [
                'done' => $history->count() > 0,
                'label' => 'Try a search in the Preview',
                'hint' => 'Run a few real queries against your index to see how results rank, what the AI Answer sounds like, and how the dashboard fills in. This is also the fastest way to spot content gaps before visitors do.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/preview'), 'label' => 'Open Preview'],
            ],
            [
                'done' => $customPrompt !== '',
                'label' => 'Customise the AI Answer system prompt',
                'hint' => 'By default the answer model is told to reply from your content in a neutral tone. Override the system prompt to match your brand voice, restrict what it can talk about, or add domain rules like always linking to the relevant product page.',
                'cta' => ['url' => $settingsBase . '#tab-ai-answer', 'label' => 'Configure'],
            ],
            [
                'done' => abs(((float)($settings->costBudgetDailyGlobal ?? 0)) - 3.0) > 0.001,
                'label' => 'Set a daily AI Answer spend cap',
                'hint' => 'Every AI Answer call costs a few cents on the OpenAI side. The daily cap pauses synthesis once spending hits your limit, so a traffic spike or a misbehaving bot can’t drain your account overnight. Default is $3/day.',
                'cta' => ['url' => $settingsBase . '#tab-ai-answer', 'label' => 'Set budget'],
            ],
        ];

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
            'trendingWindow' => $trendingWindow,
            'dailySeries' => $dailySeries,
            'aggregates' => $aggregates,
            'daysWithData' => $daysWithData,
            'minNRate' => self::MIN_N_RATE,
            'minDaysBudgetEta' => self::MIN_DAYS_BUDGET_ETA,
            'coverage' => $coverage,
            'budget' => $budget,
            'topQueries' => $topQueries,
            'trendingQueries' => $trendingQueries,
            'recentErrors' => $recentErrors,
            'recommendations' => $recommendations,
            'hasSearches' => $history->count() > 0,
            'siteCount' => $siteCount,
            'isMultisite' => $isMultisite,
            'indexedSiteCount' => $indexedSiteCount,
            'health' => $health,
            'setupComplete' => $setupComplete,
            'requiredSteps' => $requiredSteps,
            'recommendedSteps' => $recommendedSteps,
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

        $recentSearches = $sum($recent, 'searches');
        $priorSearches = $sum($prior, 'searches');
        $recentCost = $sum($recent, 'cost');
        $priorCost = $sum($prior, 'cost');

        $errors = $sum($series, 'errors');
        $searches = $sum($series, 'searches');

        return [
            'rangeDays' => $rangeDays,
            'priorWindowDays' => $half,
            'searches' => $searches,
            'searchesDelta' => $this->pctDelta($recentSearches, $priorSearches),
            'cost' => round($sum($series, 'cost'), 6),
            'costDelta' => $this->pctDelta($recentCost, $priorCost),
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
     * Each item: { state: 'ok'|'warn'|'crit', label: string }.
     */
    private function buildHealth($settings, array $stats, array $budget): array
    {
        $apiOk = !empty($settings->getOpenaiApiKey()) && (bool)($stats['isConnected'] ?? false);

        $queuePending = 0;
        try {
            foreach (Craft::$app->getQueue()->getJobInfo(100) as $info) {
                if (in_array($info['status'] ?? null, [1, 2], true)) {
                    $queuePending++;
                }
            }
        } catch (\Throwable $e) {
            $queuePending = 0;
        }

        $lastIndexed = $stats['lastIndexed'] ?? null;
        $lastSyncState = 'ok';
        $lastSyncLabel = 'Never synced';
        if ($lastIndexed) {
            try {
                $ts = $lastIndexed instanceof \DateTimeInterface
                    ? $lastIndexed
                    : new \DateTime($lastIndexed);
                $ageHours = (time() - $ts->getTimestamp()) / 3600;
                $lastSyncLabel = 'Last sync ' . $this->relativeTime($ts);
                $lastSyncState = $ageHours > 72 ? 'warn' : 'ok';
            } catch (\Throwable $e) {
                $lastSyncLabel = 'Last sync unknown';
            }
        } else {
            $lastSyncState = 'warn';
        }

        $ratio = (float)($budget['ratio'] ?? 0);
        $budgetState = $ratio >= 0.9 ? 'crit' : ($ratio >= 0.75 ? 'warn' : 'ok');
        $budgetLabel = ($budget['cap'] ?? 0) > 0
            ? 'Budget ' . round($ratio * 100) . '% of cap'
            : 'No daily cap';

        return [
            ['key' => 'api', 'state' => $apiOk ? 'ok' : 'crit', 'label' => $apiOk ? 'API connected' : 'API not connected'],
            ['key' => 'indexer', 'state' => 'ok', 'label' => $queuePending > 0 ? $queuePending . ' indexer job' . ($queuePending === 1 ? '' : 's') . ' running' : 'Indexer idle'],
            ['key' => 'sync', 'state' => $lastSyncState, 'label' => $lastSyncLabel],
            ['key' => 'budget', 'state' => $budgetState, 'label' => $budgetLabel],
        ];
    }

    private function relativeTime(\DateTimeInterface $when): string
    {
        $secs = max(1, time() - $when->getTimestamp());
        if ($secs < 60) return $secs . 's ago';
        if ($secs < 3600) return floor($secs / 60) . 'm ago';
        if ($secs < 86400) return floor($secs / 3600) . 'h ago';
        return floor($secs / 86400) . 'd ago';
    }
}
