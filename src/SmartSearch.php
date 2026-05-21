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
use ghoststreet\craftsmartsearch\assets\SmartSearchAsset;
use ghoststreet\craftsmartsearch\assets\DashboardAsset;
use ghoststreet\craftsmartsearch\assets\DataSyncAsset;
use ghoststreet\craftsmartsearch\assets\DebugAsset;
use ghoststreet\craftsmartsearch\assets\HistoryAsset;
use ghoststreet\craftsmartsearch\assets\IndexMgmtAsset;
use ghoststreet\craftsmartsearch\assets\InsightsAsset;
use ghoststreet\craftsmartsearch\assets\PreviewAsset;
use ghoststreet\craftsmartsearch\assets\SettingsAsset;
use ghoststreet\craftsmartsearch\jobs\DeleteEntryJob;
use ghoststreet\craftsmartsearch\jobs\IndexEntryJob;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\services\BM25Service;
use ghoststreet\craftsmartsearch\services\DatabaseService;
use ghoststreet\craftsmartsearch\services\EmbeddingService;
use ghoststreet\craftsmartsearch\services\ExclusionService;
use ghoststreet\craftsmartsearch\services\HistoryService;
use ghoststreet\craftsmartsearch\services\HybridSearchService;
use ghoststreet\craftsmartsearch\services\IndexingDebugService;
use ghoststreet\craftsmartsearch\services\OpenAIClientFactory;
use ghoststreet\craftsmartsearch\services\RagSearchService;
use ghoststreet\craftsmartsearch\services\RateLimitService;
use ghoststreet\craftsmartsearch\services\RecommendationsService;
use ghoststreet\craftsmartsearch\services\SearchService;
use ghoststreet\craftsmartsearch\variables\SmartSearchVariable;
use yii\base\Event;
use yii\log\FileTarget;
use yii\web\Response;

/**
 * AI-powered semantic search plugin for Craft CMS.
 * Provides vector-based search, BM25 keyword scoring, hybrid RRF fusion, and RAG summaries
 * backed by PostgreSQL with pgvector and the OpenAI embeddings API.
 *
 * @method static SmartSearch getInstance()
 * @author Ghost Street <dev@ghost.st>
 * @copyright Ghost Street
 * @license https://craftcms.github.io/license/ Craft License
 * @property-read DatabaseService $databaseService
 * @property-read EmbeddingService $embeddingService
 * @property-read SearchService $searchService
 * @property-read BM25Service $bm25Service
 * @property-read HybridSearchService $hybridSearchService
 * @property-read RagSearchService $ragSearchService
 * @property-read RateLimitService $rateLimitService
 * @property-read IndexingDebugService $indexingDebugService
 * @property-read ExclusionService $exclusionService
 * @property-read OpenAIClientFactory $openAIClientFactory
 * @property-read HistoryService $historyService
 * @property-read RecommendationsService $recommendationsService
 */
class SmartSearch extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    private ?Settings $cachedSettings = null;

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();

        $this->attachEventHandlers();
    }

    /**
     * Register a custom log target for smart-search logs
     * and exclude from default targets to prevent duplicate logging.
     */
    private function registerLogTarget(): void
    {
        $dispatcher = Craft::getLogger()->dispatcher;

        $logTarget = new FileTarget([
            'logFile' => Craft::getAlias('@storage/logs/smart-search.log'),
            'categories' => ['smart-search'],
            'logVars' => [],
        ]);

        $dispatcher->targets['smart-search'] = $logTarget;

        foreach ($dispatcher->targets as $key => $target) {
            if ($key === 'smart-search') {
                continue;
            }

            if ($target instanceof FileTarget) {
                $target->except = array_merge($target->except ?? [], ['smart-search']);
            }
        }
    }

    public static function config(): array
    {
        return [
            'components' => [
                'openAIClientFactory' => OpenAIClientFactory::class,
                'databaseService' => DatabaseService::class,
                'embeddingService' => EmbeddingService::class,
                'searchService' => SearchService::class,
                'bm25Service' => BM25Service::class,
                'hybridSearchService' => HybridSearchService::class,
                'ragSearchService' => RagSearchService::class,
                'rateLimitService' => RateLimitService::class,
                'indexingDebugService' => IndexingDebugService::class,
                'exclusionService' => ExclusionService::class,
                'historyService' => HistoryService::class,
                'recommendationsService' => RecommendationsService::class,
            ],
        ];
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * Get plugin settings with request-level caching.
     */
    public function getSettings(): ?Settings
    {
        if ($this->cachedSettings === null) {
            $this->cachedSettings = parent::getSettings();
        }

        return $this->cachedSettings;
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
        if ($this->historyService->detailsCount() > 0) {
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

        Craft::$app->getCache()->delete(DatabaseService::SCHEMA_CACHE_KEY);
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
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['api/smart-search'] = 'smart-search/search/index';
                $event->rules['api/smart-search/hybrid'] = 'smart-search/search/semantic-search';
                $event->rules['api/smart-search/rag'] = 'smart-search/search/rag-search';
                $event->rules['api/smart-search/rag/stream'] = 'smart-search/search/rag-stream';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['smart-search'] = 'smart-search/dashboard/index';

                $event->rules['smart-search/settings'] = 'smart-search/settings/index';

                $event->rules['POST smart-search/settings/save'] = 'smart-search/settings/save';
                $event->rules['POST smart-search/settings/test-database-connection'] = 'smart-search/settings/test-database-connection';
                $event->rules['POST smart-search/settings/test-api-key'] = 'smart-search/settings/test-api-key';

                // Index management (consolidated from data-sync + debug)
                $event->rules['smart-search/index'] = 'smart-search/index/index';
                $event->rules['smart-search/index/entry'] = 'smart-search/index/entry';
                $event->rules['POST smart-search/index/sync'] = 'smart-search/index/sync';
                $event->rules['POST smart-search/index/cancel-sync'] = 'smart-search/index/cancel-sync';
                $event->rules['POST smart-search/index/get-stats'] = 'smart-search/index/get-stats';
                $event->rules['POST smart-search/index/exclude-entry'] = 'smart-search/index/exclude-entry';
                $event->rules['POST smart-search/index/include-entry'] = 'smart-search/index/include-entry';

                // Insights (consolidated from history + keywords)
                $event->rules['smart-search/insights'] = 'smart-search/insights/index';
                $event->rules['smart-search/insights/<id:\\d+>'] = 'smart-search/insights/detail';
                $event->rules['POST smart-search/insights/prune'] = 'smart-search/insights/prune';

                // Preview
                $event->rules['smart-search/preview'] = 'smart-search/preview/index';

                // Legacy redirects
                $event->rules['smart-search/data-sync'] = 'smart-search/index/legacy-redirect';
                $event->rules['smart-search/debug'] = 'smart-search/index/legacy-redirect';
                $event->rules['smart-search/history'] = 'smart-search/insights/legacy-redirect';
                $event->rules['smart-search/history/keywords'] = 'smart-search/insights/legacy-redirect';
                $event->rules['smart-search/history/<id:\\d+>'] = 'smart-search/insights/detail';
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
                    'smart-search/preview'    => PreviewAsset::class,
                    'smart-search/index-mgmt' => IndexMgmtAsset::class,
                    'smart-search/insights'   => InsightsAsset::class,
                    'smart-search/debug'      => DebugAsset::class,
                    'smart-search/history'    => HistoryAsset::class,
                    'smart-search/settings'   => SettingsAsset::class,
                    'smart-search/data-sync'  => DataSyncAsset::class,
                    'smart-search/index'      => DashboardAsset::class,
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
