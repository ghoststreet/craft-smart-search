<?php

namespace ghoststreet\craftaisearch;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use ghoststreet\craftaisearch\exceptions\DatabaseException;
use ghoststreet\craftaisearch\helpers\Logger;
use ghoststreet\craftaisearch\jobs\DeleteEntryJob;
use ghoststreet\craftaisearch\jobs\IndexEntryJob;
use ghoststreet\craftaisearch\models\Settings;
use ghoststreet\craftaisearch\services\BM25Service;
use ghoststreet\craftaisearch\services\DatabaseService;
use ghoststreet\craftaisearch\services\EmbeddingService;
use ghoststreet\craftaisearch\services\HybridSearchService;
use ghoststreet\craftaisearch\services\IndexingDebugService;
use ghoststreet\craftaisearch\services\OpenAIClientFactory;
use ghoststreet\craftaisearch\services\RagSearchService;
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
 * @property-read IndexingDebugService $indexingDebugService
 * @property-read OpenAIClientFactory $openAIClientFactory
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

        Craft::$app->onInit(function() {
            if (Craft::$app->getCache()->get(DatabaseService::SCHEMA_CACHE_KEY)) {
                return;
            }

            try {
                if (!$this->databaseService->isSchemaInitialized()) {
                    $this->databaseService->initializeSchema();
                }

                Craft::$app->getCache()->set(DatabaseService::SCHEMA_CACHE_KEY, true, 3600);
            } catch (DatabaseException $e) {
                Logger::warning('Skipping schema initialization: ' . $e->getMessage());
            }
        });
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
                'indexingDebugService' => IndexingDebugService::class,
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

        if (Craft::$app->getConfig()->general->allowAdminChanges && Craft::$app->user->getIsAdmin()) {
            $subNav['settings'] = ['label' => 'Settings', 'url' => 'ai-search/settings'];
        }

        $subNav['data-sync'] = ['label' => 'Data Sync', 'url' => 'ai-search/data-sync'];
        $subNav['debug'] = ['label' => 'Debug', 'url' => 'ai-search/debug'];

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

        try {
            $this->databaseService->getConnection()->exec(
                'DROP TABLE IF EXISTS ' . DatabaseService::TABLE_NAME
            );
            Craft::$app->getCache()->delete(DatabaseService::SCHEMA_CACHE_KEY);
        } catch (\Throwable $e) {
            Craft::error("Could not drop AI Search table: {$e->getMessage()}", __METHOD__);
            throw $e;
        }
    }

    /**
     * Check if an entry's section is allowed for indexing based on settings.
     */
    private function isSectionAllowed(Entry $entry): bool
    {
        $settings = $this->getSettings();
        $allowedSections = $settings->indexableSections;

        if (empty($allowedSections)) {
            return true;
        }

        $section = $entry->getSection();

        return $section !== null && in_array($section->handle, $allowedSections, true);
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
                    $element->getUrl() !== null &&
                    $this->isSectionAllowed($element)) {
                    $job = $element->getStatus() === Entry::STATUS_DISABLED
                        ? new DeleteEntryJob(['entryId' => $element->id, 'siteId' => $element->siteId])
                        : new IndexEntryJob(['entryId' => $element->id, 'siteId' => $element->siteId]);
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

                $event->rules['ai-search/data-sync'] = 'ai-search/data-sync/index';
                $event->rules['POST ai-search/data-sync/wipe-and-reindex'] = 'ai-search/data-sync/wipe-and-reindex';
                $event->rules['POST ai-search/data-sync/get-stats'] = 'ai-search/data-sync/get-stats';

                $event->rules['ai-search/debug'] = 'ai-search/debug/index';
                $event->rules['ai-search/debug/entry'] = 'ai-search/debug/entry';
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                $event->sender->set('aiSearch', AiSearchVariable::class);
            }
        );
    }
}
