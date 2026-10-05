<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\helpers\UrlHelper;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\services\HistoryService;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\web\Response;

/**
 * Aggregator for the Smart Search dashboard. Pulls daily series, top/trending
 * queries, and recent errors over the last RANGE_DAYS days; index
 * coverage and budget consumption are range-independent.
 */
class DashboardController extends BaseCpController
{
    private const RANGE_DAYS = 30;

    /** Set once this user has run a search in the Preview, which ticks the dashboard step. */
    public const PREVIEW_TRIED_PREFERENCE = 'smartSearchPreviewTried';

    /** Set once this user has opened the AI Answer settings, which ticks the prompt step. */
    public const PROMPT_SEEN_PREFERENCE = 'smartSearchPromptSeen';

    /** Days of real traffic needed before the burn rate is trustworthy enough to project an ETA. */
    private const MIN_DAYS_BUDGET_ETA = 7;
    /** An ETA further out than this isn't worth showing. */
    private const MAX_DAYS_BUDGET_ETA = 30;

    public function actionIndex(): Response
    {
        $range = self::RANGE_DAYS;
        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();
        $history = $plugin->historyService;
        $stats = $plugin->engine()->stats();

        $metrics = $this->loadMetrics($history, $range);
        $coverage = $plugin->indexInspectionService->getCachedCoverageBySite();
        $budget = $plugin->rateLimitService->getBudgetConsumption($metrics['sevenDayBurn']);

        $setupComplete = $stats['entryCount'] > 0;
        $hasSearches = $history->hasAny();

        return $this->renderTemplate('smart-search/index', [
            'stats' => $stats,
            'range' => $range,
            'seriesSearches' => $this->chartSeries($metrics['dailySeries'], 'searches'),
            'seriesCost' => $this->chartSeries($metrics['dailySeries'], 'cost'),
            'aggregates' => [
                'rangeDays' => $range,
                'searches' => array_sum(array_column($metrics['dailySeries'], 'searches')),
                'cost' => round(array_sum(array_column($metrics['dailySeries'], 'cost')), 6),
            ],
            'usage' => $history->getStats($range),
            'coverage' => $coverage,
            'coverageTotal' => array_sum(array_column($coverage, 'total')),
            'budget' => $this->gateBudgetEta($budget, $metrics['daysWithData']),
            'topQueries' => $metrics['topQueries'],
            'trendingQueries' => $metrics['trendingQueries'],
            'trendingDays' => HistoryService::TRENDING_DAYS,
            'recentErrors' => $metrics['recentErrors'],
            'hasSearches' => $hasSearches,
            'setupComplete' => $setupComplete,
            'canReduceDimensions' => $settings->provider()->offersWidthChoice() && $settings->dimensions > min($settings->dimensionChoices()),
            'requiredSteps' => $this->buildRequiredSteps($setupComplete),
            'recommendedSteps' => $this->buildRecommendedSteps($settings, $setupComplete),
            'guideDismissed' => (bool)Craft::$app->getUser()->getIdentity()->getPreference('smartSearchGuideDismissed'),
        ]);
    }

    /** Tick a recommended step for the current user, writing only the first time. */
    public static function markStepDone(string $preference): void
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user->getPreference($preference)) {
            Craft::$app->getUsers()->saveUserPreferences($user, [$preference => true]);
        }
    }

    /**
     * AJAX: dismiss the post-setup recommended-steps guide card for the current user.
     */
    public function actionDismissGuide(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        Craft::$app->getUsers()->saveUserPreferences(Craft::$app->getUser()->getIdentity(), ['smartSearchGuideDismissed' => true]);

        return $this->asJson(['success' => true]);
    }

    private function loadMetrics(HistoryService $history, int $range): array
    {
        $dailySeries = $history->getDailySeries($range);
        $sevenDay = array_slice($dailySeries, -7);
        $sevenDayBurn = array_sum(array_column($sevenDay, 'cost')) / 7.0;

        return [
            'dailySeries' => $dailySeries,
            'topQueries' => $history->getTopKeywords($range, 10),
            'trendingQueries' => $history->getTrendingKeywords(null, 10),
            'recentErrors' => $history->getRecentErrors(10),
            'sevenDayBurn' => $sevenDayBurn,
            'daysWithData' => count(array_filter($dailySeries, static fn($r) => $r['searches'] > 0)),
        ];
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
     * Blank the budget ETA unless it's both trustworthy and near enough to act on,
     * so the template can just ask whether there's an ETA to show.
     */
    private function gateBudgetEta(array $budget, int $daysWithData): array
    {
        $eta = $budget['etaDays'];
        $worthShowing = $eta !== null
            && $eta < self::MAX_DAYS_BUDGET_ETA
            && $daysWithData >= self::MIN_DAYS_BUDGET_ETA;
        $budget['etaDays'] = $worthShowing ? $eta : null;

        return $budget;
    }

    private function buildRequiredSteps(bool $hasIndex): array
    {
        return [
            [
                'done' => $hasIndex,
                'label' => 'Index your content',
                'hint' => 'This reads every published entry and stores a searchable copy in your own Craft database, then learns which words your content relates. It starts on its own when the plugin is installed; run your queue to finish it. After that, entries are kept up to date automatically as you edit them.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/index'), 'label' => 'Open Index'],
            ],
        ];
    }

    private function buildRecommendedSteps(Settings $settings, bool $hasIndex): array
    {
        $steps = [];
        if ($hasIndex) {
            $steps[] = [
                'done' => (bool)Craft::$app->getUser()->getIdentity()->getPreference(self::PREVIEW_TRIED_PREFERENCE),
                'label' => 'Try a search in the Preview',
                'hint' => 'Run a few real questions to see how results rank and what the AI Answer sounds like. It is the fastest way to spot gaps in your content before visitors find them.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/preview'), 'label' => 'Open Preview'],
            ];
        }
        $steps[] = [
            'done' => $settings->hasProviderKey(),
            'label' => 'Add an AI provider API key',
            'hint' => 'Optional. Without one, search matches on keywords and on the terms your own content puts side by side. A key swaps that second signal for a general embedding model, which knows words are related even when your site never writes them together, and it is what AI Answer needs.',
            'cta' => ['url' => UrlHelper::cpUrl('smart-search/settings/connections'), 'label' => 'Add API key'],
        ];
        if ($settings->hasProviderKey()) {
            $steps[] = [
                'done' => (bool)Craft::$app->getUser()->getIdentity()->getPreference(self::PROMPT_SEEN_PREFERENCE),
                'label' => 'Customise the AI Answer system prompt',
                'hint' => 'By default the answer model is told to reply from your content in a neutral tone. Override the system prompt to match your brand voice, restrict what it can talk about, or add domain rules like always linking to the relevant product page.',
                'cta' => ['url' => UrlHelper::cpUrl('smart-search/settings/ai-answer'), 'label' => 'Configure'],
            ];
        }
        return $steps;
    }
}
