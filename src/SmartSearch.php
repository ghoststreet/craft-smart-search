<?php

namespace ghoststreet\craftsmartsearch;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use ghoststreet\craftsmartsearch\assets\DashboardAsset;
use ghoststreet\craftsmartsearch\assets\IndexEntryAsset;
use ghoststreet\craftsmartsearch\assets\IndexMgmtAsset;
use ghoststreet\craftsmartsearch\assets\InsightsAsset;
use ghoststreet\craftsmartsearch\assets\PreviewAsset;
use ghoststreet\craftsmartsearch\assets\SettingsAsset;
use ghoststreet\craftsmartsearch\assets\SmartSearchAsset;
use ghoststreet\craftsmartsearch\jobs\DeleteEntryJob;
use ghoststreet\craftsmartsearch\jobs\IndexEntryJob;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\services\AiAnswerService;
use ghoststreet\craftsmartsearch\services\BoostService;
use ghoststreet\craftsmartsearch\services\DatabaseService;
use ghoststreet\craftsmartsearch\services\DictionaryService;
use ghoststreet\craftsmartsearch\services\EmbeddingService;
use ghoststreet\craftsmartsearch\services\ExclusionService;
use ghoststreet\craftsmartsearch\services\HistoryService;
use ghoststreet\craftsmartsearch\services\IndexInspectionService;
use ghoststreet\craftsmartsearch\services\KeywordSearchService;
use ghoststreet\craftsmartsearch\services\OpenAIClientFactory;
use ghoststreet\craftsmartsearch\services\QueryCorrectorService;
use ghoststreet\craftsmartsearch\services\RateLimitService;
use ghoststreet\craftsmartsearch\services\RecommendationsService;
use ghoststreet\craftsmartsearch\services\SearchService;
use ghoststreet\craftsmartsearch\services\SmartSearchService;
use ghoststreet\craftsmartsearch\variables\SmartSearchVariable;
use yii\base\Event;
use yii\log\FileTarget;
use yii\web\Response;

/**
 * AI-powered semantic search plugin for Craft CMS.
 * Provides semantic search, keyword scoring, RRF fusion, and AI Answer summaries
 * backed by PostgreSQL with pgvector and the OpenAI embeddings API.
 *
 * @method static SmartSearch getInstance()
 * @method Settings|null getSettings()
 * @author Ghost Street <dev@ghost.st>
 * @copyright Ghost Street
 * @license https://craftcms.github.io/license/ Craft License
 * @property-read DatabaseService $databaseService
 * @property-read EmbeddingService $embeddingService
 * @property-read SearchService $searchService
 * @property-read KeywordSearchService $keywordSearchService
 * @property-read SmartSearchService $smartSearchService
 * @property-read AiAnswerService $aiAnswerService
 * @property-read RateLimitService $rateLimitService
 * @property-read IndexInspectionService $indexInspectionService
 * @property-read ExclusionService $exclusionService
 * @property-read OpenAIClientFactory $openAIClientFactory
 * @property-read HistoryService $historyService
 * @property-read RecommendationsService $recommendationsService
 * @property-read DictionaryService $dictionaryService
 * @property-read QueryCorrectorService $queryCorrectorService
 * @property-read BoostService $boostService
 */
class SmartSearch extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();

        $this->attachEventHandlers();
    }

    private function registerLogTarget(): void
    {
        $dispatcher = Craft::getLogger()->dispatcher;

        $logTarget = new FileTarget([
            'logFile' => Craft::getAlias('@storage/logs/smart-search.log'),
            'categories' => ['smart-search'],
            'logVars' => [],
        ]);

        $dispatcher->targets['smart-search'] = $logTarget;
    }

    public static function config(): array
    {
        return [
            'components' => [
                'openAIClientFactory' => OpenAIClientFactory::class,
                'databaseService' => DatabaseService::class,
                'embeddingService' => EmbeddingService::class,
                'searchService' => SearchService::class,
                'keywordSearchService' => KeywordSearchService::class,
                'smartSearchService' => SmartSearchService::class,
                'aiAnswerService' => AiAnswerService::class,
                'rateLimitService' => RateLimitService::class,
                'indexInspectionService' => IndexInspectionService::class,
                'exclusionService' => ExclusionService::class,
                'historyService' => HistoryService::class,
                'recommendationsService' => RecommendationsService::class,
                'dictionaryService' => DictionaryService::class,
                'queryCorrectorService' => QueryCorrectorService::class,
                'boostService' => BoostService::class,
            ],
        ];
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Smart Search';

        $subNav = [];

        $settings = $this->getSettings();
        $connectionsConfigured = !empty($settings->getOpenaiApiKey())
            && !empty($settings->getPostgresqlHost())
            && !empty($settings->getPostgresqlDatabase());

        $subNav['dashboard'] = ['label' => 'Dashboard', 'url' => 'smart-search'];
        if ($this->historyService->count() > 0) {
            $subNav['insights'] = ['label' => 'Insights', 'url' => 'smart-search/insights'];
        }
        if ($connectionsConfigured) {
            $subNav['index'] = ['label' => 'Index', 'url' => 'smart-search/index'];

            $stats = $this->databaseService->getStatsSafe();
            $hasIndexedEntries = (int)($stats['entryCount'] ?? 0) > 0;

            if ($hasIndexedEntries) {
                $subNav['preview'] = ['label' => 'Preview', 'url' => 'smart-search/preview'];
            }
        }

        if (Craft::$app->getConfig()->general->allowAdminChanges && Craft::$app->user->getIsAdmin()) {
            $subNav['settings'] = ['label' => 'Settings', 'url' => 'smart-search/settings'];
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    public function getSettingsResponse(): Response
    {
        return Craft::$app->controller->redirect(
            UrlHelper::cpUrl('smart-search')
        );
    }

    protected function afterUninstall(): void
    {
        parent::afterUninstall();

        $cache = Craft::$app->getCache();
        $cache->delete(DatabaseService::SCHEMA_CACHE_KEY);
        $cache->delete('smart_search_dashboard_stats');
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(ElementEvent $event) {
                $element = $event->element;

                if ($element instanceof Entry &&
                    !$element->getIsDraft() &&
                    !$element->getIsRevision() &&
                    $element->getUrl() !== null) {
                    $enabledForSite = $element->getEnabledForSite((int)$element->siteId);
                    $job = $enabledForSite
                        ? new IndexEntryJob(['entryId' => $element->id, 'siteId' => $element->siteId])
                        : new DeleteEntryJob(['entryId' => $element->id, 'siteId' => $element->siteId]);
                    Craft::$app->getQueue()->push($job);
                }
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function(ElementEvent $event) {
                $element = $event->element;
                if ($element instanceof Entry) {
                    Craft::$app->getQueue()->push(new DeleteEntryJob([
                        'entryId' => $element->id,
                        'siteId' => $element->siteId,
                    ]));
                }
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['smart-search'] = 'smart-search/dashboard/index';

                $event->rules['smart-search/settings'] = 'smart-search/settings/index';
                $event->rules['smart-search/settings/connections'] = 'smart-search/settings/connections';
                $event->rules['smart-search/settings/connections/openai'] = 'smart-search/settings/connections-openai';
                $event->rules['smart-search/settings/connections/postgres'] = 'smart-search/settings/connections-postgres';
                $event->rules['smart-search/settings/indexing'] = 'smart-search/settings/indexing';
                $event->rules['smart-search/settings/smart-search'] = 'smart-search/settings/smart-search';
                $event->rules['smart-search/settings/ai-answer'] = 'smart-search/settings/ai-answer';
                $event->rules['smart-search/settings/advanced'] = 'smart-search/settings/advanced';

                $event->rules['POST smart-search/settings/save'] = 'smart-search/settings/save';
                $event->rules['POST smart-search/settings/test-database-connection'] = 'smart-search/settings/test-database-connection';
                $event->rules['POST smart-search/settings/test-api-key'] = 'smart-search/settings/test-api-key';

                // Index management
                $event->rules['smart-search/index'] = 'smart-search/index/index';
                $event->rules['smart-search/index/entries'] = 'smart-search/index/entries';
                $event->rules['smart-search/index/coverage'] = 'smart-search/index/coverage';
                $event->rules['smart-search/index/entry'] = 'smart-search/index/entry';
                $event->rules['POST smart-search/index/sync'] = 'smart-search/index/sync';
                $event->rules['POST smart-search/index/cancel-sync'] = 'smart-search/index/cancel-sync';
                $event->rules['POST smart-search/index/get-stats'] = 'smart-search/index/get-stats';
                $event->rules['POST smart-search/index/exclude-entry'] = 'smart-search/index/exclude-entry';
                $event->rules['POST smart-search/index/include-entry'] = 'smart-search/index/include-entry';

                // Insights — one route per page
                $event->rules['smart-search/insights'] = 'smart-search/insights/index';
                $event->rules['smart-search/insights/top-queries'] = 'smart-search/insights/top-queries';
                $event->rules['smart-search/insights/zero-results'] = 'smart-search/insights/zero-results';
                $event->rules['smart-search/insights/trending'] = 'smart-search/insights/trending';

                // Preview
                $event->rules['smart-search/preview'] = 'smart-search/preview/index';
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('smartSearch', SmartSearchVariable::class);
            }
        );

        $this->registerCpAssetBundles();
    }

    /**
     * Route CP asset bundles per page template. Mirrors the Lens plugin's
     * approach: a single event handler in the plugin class, no scattered
     * registerAssetBundle() calls in controllers.
     */
    private function registerCpAssetBundles(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                if (!Craft::$app->getRequest()->getIsCpRequest()) {
                    return;
                }
                $template = (string)$event->template;
                if (!str_starts_with($template, 'smart-search/')) {
                    return;
                }

                $view = Craft::$app->getView();
                $map = [
                    'smart-search/preview' => PreviewAsset::class,
                    'smart-search/index-mgmt/entry' => IndexEntryAsset::class,
                    'smart-search/index-mgmt' => IndexMgmtAsset::class,
                    'smart-search/insights' => InsightsAsset::class,
                    'smart-search/settings' => SettingsAsset::class,
                    'smart-search/index' => DashboardAsset::class,
                ];

                foreach ($map as $prefix => $bundle) {
                    if ($template === $prefix || str_starts_with($template, $prefix . '/')) {
                        $view->registerAssetBundle($bundle);
                        return;
                    }
                }

                $view->registerAssetBundle(SmartSearchAsset::class);
            }
        );
    }
}
