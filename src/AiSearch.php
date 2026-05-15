<?php

namespace ghoststreet\craftaisearch;

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
use ghoststreet\craftaisearch\assets\CraftSearchAsset;
use ghoststreet\craftaisearch\assets\DashboardAsset;
use ghoststreet\craftaisearch\assets\DebugAsset;
use ghoststreet\craftaisearch\assets\HistoryAsset;
use ghoststreet\craftaisearch\assets\IndexMgmtAsset;
use ghoststreet\craftaisearch\assets\InsightsAsset;
use ghoststreet\craftaisearch\jobs\DeleteEntryJob;
use ghoststreet\craftaisearch\jobs\IndexEntryJob;
use ghoststreet\craftaisearch\models\Settings;
use ghoststreet\craftaisearch\services\BM25Service;
use ghoststreet\craftaisearch\services\DatabaseService;
use ghoststreet\craftaisearch\services\EmbeddingService;
use ghoststreet\craftaisearch\services\HistoryService;
use ghoststreet\craftaisearch\services\HybridSearchService;
use ghoststreet\craftaisearch\services\IndexingDebugService;
use ghoststreet\craftaisearch\services\OpenAIClientFactory;
use ghoststreet\craftaisearch\services\RagSearchService;
use ghoststreet\craftaisearch\services\RateLimitService;
use ghoststreet\craftaisearch\services\RecommendationsService;
use ghoststreet\craftaisearch\services\SearchService;
use ghoststreet\craftaisearch\variables\AiSearchVariable;
use yii\base\Event;
use yii\log\FileTarget;
use yii\web\Response;

/**
 * AI-powered semantic search plugin for Craft CMS.
 * Provides vector-based search, BM25 keyword scoring, hybrid RRF fusion, and RAG summaries
 * backed by PostgreSQL with pgvector and the OpenAI embeddings API.
 *
 * @method static AiSearch getInstance()
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
 * @property-read OpenAIClientFactory $openAIClientFactory
 * @property-read HistoryService $historyService
 * @property-read RecommendationsService $recommendationsService
 */
class AiSearch extends Plugin
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
     * Register a custom log target for ai-search logs
     * and exclude from default targets to prevent duplicate logging.
     */
    private function registerLogTarget(): void
    {
        $dispatcher = Craft::getLogger()->dispatcher;

        $logTarget = new FileTarget([
            'logFile' => Craft::getAlias('@storage/logs/ai-search.log'),
            'categories' => ['ai-search'],
            'logVars' => [],
        ]);

        $dispatcher->targets['ai-search'] = $logTarget;

        foreach ($dispatcher->targets as $key => $target) {
            if ($key === 'ai-search') {
                continue;
            }

            if ($target instanceof FileTarget) {
                $target->except = array_merge($target->except ?? [], ['ai-search']);
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
        $item['label'] = 'AI Search';

        $subNav = [];

        $subNav['dashboard'] = ['label' => 'Dashboard', 'url' => 'ai-search'];
        $subNav['insights'] = ['label' => 'Insights', 'url' => 'ai-search/insights'];
        $subNav['index'] = ['label' => 'Index', 'url' => 'ai-search/index'];

        if (Craft::$app->getConfig()->general->allowAdminChanges && Craft::$app->user->getIsAdmin()) {
            $subNav['settings'] = ['label' => 'Settings', 'url' => 'ai-search/settings'];
        }

        $item['subnav'] = $subNav;

        return $item;
    }

    public function getSettingsResponse(): Response
    {
        return Craft::$app->controller->redirect(
            UrlHelper::cpUrl('ai-search')
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
                $event->rules['api/hybrid-search'] = 'ai-search/search/semantic-search';
                $event->rules['api/craft-search'] = 'ai-search/search/craft-search';
                $event->rules['api/rag-search'] = 'ai-search/search/rag-search';
                $event->rules['api/rag-search/stream'] = 'ai-search/search/rag-stream';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['ai-search'] = 'ai-search/dashboard/index';

                $event->rules['ai-search/settings'] = 'ai-search/settings/index';

                $event->rules['POST ai-search/settings/save'] = 'ai-search/settings/save';
                $event->rules['POST ai-search/settings/test-database-connection'] = 'ai-search/settings/test-database-connection';
                $event->rules['POST ai-search/settings/test-api-key'] = 'ai-search/settings/test-api-key';

                // Index management (consolidated from data-sync + debug)
                $event->rules['ai-search/index'] = 'ai-search/index/index';
                $event->rules['ai-search/index/entry'] = 'ai-search/index/entry';
                $event->rules['POST ai-search/index/wipe-and-reindex'] = 'ai-search/index/wipe-and-reindex';
                $event->rules['POST ai-search/index/get-stats'] = 'ai-search/index/get-stats';

                // Insights (consolidated from history + keywords)
                $event->rules['ai-search/insights'] = 'ai-search/insights/index';
                $event->rules['ai-search/insights/<id:\\d+>'] = 'ai-search/insights/detail';
                $event->rules['POST ai-search/insights/prune'] = 'ai-search/insights/prune';
                $event->rules['POST ai-search/insights/clear'] = 'ai-search/insights/clear';

                // Legacy redirects
                $event->rules['ai-search/data-sync'] = 'ai-search/index/legacy-redirect';
                $event->rules['ai-search/debug'] = 'ai-search/index/legacy-redirect';
                $event->rules['ai-search/history'] = 'ai-search/insights/legacy-redirect';
                $event->rules['ai-search/history/keywords'] = 'ai-search/insights/legacy-redirect';
                $event->rules['ai-search/history/<id:\\d+>'] = 'ai-search/insights/detail';
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('aiSearch', AiSearchVariable::class);
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
                if (!str_starts_with($template, 'ai-search/')) {
                    return;
                }

                $view = Craft::$app->getView();
                $map = [
                    'ai-search/index-mgmt' => IndexMgmtAsset::class,
                    'ai-search/insights'   => InsightsAsset::class,
                    'ai-search/debug'      => DebugAsset::class,
                    'ai-search/history'    => HistoryAsset::class,
                    'ai-search/index'      => DashboardAsset::class,
                ];

                foreach ($map as $prefix => $bundle) {
                    if ($template === $prefix || str_starts_with($template, $prefix . '/')) {
                        $view->registerAssetBundle($bundle);
                        return;
                    }
                }

                $view->registerAssetBundle(CraftSearchAsset::class);
            }
        );
    }
}
