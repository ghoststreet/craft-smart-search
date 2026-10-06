<?php

namespace ghoststreet\craftsmartsearch;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\ConfigEvent;
use craft\events\DeleteSiteEvent;
use craft\events\ElementEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\services\Search;
use craft\services\Sites;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use ghoststreet\craftsmartsearch\assets\DashboardAsset;
use ghoststreet\craftsmartsearch\assets\IndexEntryAsset;
use ghoststreet\craftsmartsearch\assets\IndexMgmtAsset;
use ghoststreet\craftsmartsearch\assets\PreviewAsset;
use ghoststreet\craftsmartsearch\assets\SettingsAsset;
use ghoststreet\craftsmartsearch\assets\SmartSearchAsset;
use ghoststreet\craftsmartsearch\embeddings\VectorSource;
use ghoststreet\craftsmartsearch\embeddings\VectorSourceRegistry;
use ghoststreet\craftsmartsearch\engines\EngineRegistry;
use ghoststreet\craftsmartsearch\engines\SearchEngine;
use ghoststreet\craftsmartsearch\gql\SmartSearchGql;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\models\Settings;
use ghoststreet\craftsmartsearch\providers\ProviderRegistry;
use ghoststreet\craftsmartsearch\services\AiAnswerService;
use ghoststreet\craftsmartsearch\services\AiClientFactory;
use ghoststreet\craftsmartsearch\services\EmbeddingService;
use ghoststreet\craftsmartsearch\services\ExclusionService;
use ghoststreet\craftsmartsearch\services\HistoryService;
use ghoststreet\craftsmartsearch\services\IndexInspectionService;
use ghoststreet\craftsmartsearch\services\NativeSearch;
use ghoststreet\craftsmartsearch\services\RateLimitService;
use ghoststreet\craftsmartsearch\services\SearchRunner;
use ghoststreet\craftsmartsearch\variables\SmartSearchVariable;
use yii\base\Event;
use yii\log\FileTarget;
use yii\web\Response;

/**
 * AI-powered semantic search plugin for Craft CMS.
 * Provides semantic search, keyword scoring, RRF fusion, and AI Answer summaries
 * from Craft's own database and the OpenAI embeddings API.
 *
 * @method static SmartSearch getInstance()
 * @method Settings getSettings()
 * @author Ghost Street <dev@ghost.st>
 * @copyright Ghost Street
 * @license https://craftcms.github.io/license/ Craft License
 * @property-read EmbeddingService $embeddingService
 * @property-read AiAnswerService $aiAnswerService
 * @property-read RateLimitService $rateLimitService
 * @property-read IndexInspectionService $indexInspectionService
 * @property-read ExclusionService $exclusionService
 * @property-read AiClientFactory $aiClientFactory
 * @property-read ProviderRegistry $providers
 * @property-read HistoryService $historyService
 * @property-read EngineRegistry $engines
 * @property-read VectorSourceRegistry $vectorSources
 * @property-read SearchRunner $searchRunner
 */
class SmartSearch extends Plugin
{
    public const WIKI_URL = 'https://github.com/ghoststreet/craft-smart-search/wiki';

    /**
     * Fired per formatted result so a listener can project field data into the payload.
     *
     * Attached to the plugin rather than to an engine: which engine answered is an
     * implementation detail, and a listener written against one must keep working on
     * any other.
     *
     * @see \ghoststreet\craftsmartsearch\events\FormatSearchResultEvent
     */
    public const EVENT_FORMAT_RESULT = 'formatSearchResult';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasReadOnlyCpSettings = true;
    public bool $hasCpSection = true;

    /** The engine serving this site, chosen by what its database can do. */
    public function engine(): SearchEngine
    {
        return $this->engines->active();
    }

    public function vectorSource(): VectorSource
    {
        return $this->vectorSources->active();
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();

        $this->attachEventHandlers();

        if ($this->getSettings()->enhanceNativeSearch) {
            $this->registerNativeSearch();
        }
    }

    /**
     * Swap Craft's `search` component for NativeSearch, carrying its config over. Left
     * alone when something else already replaced it: two plugins cannot both own it.
     */
    private function registerNativeSearch(): void
    {
        $search = Craft::$app->getSearch();
        if (get_class($search) !== Search::class) {
            Logger::warning('Craft search enhancement skipped: the search component is already replaced', ['class' => get_class($search)]);
            return;
        }

        Craft::$app->set('search', [
            'class' => NativeSearch::class,
            'useFullText' => $search->useFullText,
            'minFullTextWordLength' => $search->minFullTextWordLength,
            'maxPostgresKeywordLength' => $search->maxPostgresKeywordLength,
        ]);
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
                'aiClientFactory' => AiClientFactory::class,
                'providers' => ProviderRegistry::class,
                'embeddingService' => EmbeddingService::class,
                'aiAnswerService' => AiAnswerService::class,
                'rateLimitService' => RateLimitService::class,
                'indexInspectionService' => IndexInspectionService::class,
                'exclusionService' => ExclusionService::class,
                'historyService' => HistoryService::class,
                'engines' => EngineRegistry::class,
                'vectorSources' => VectorSourceRegistry::class,
                'searchRunner' => SearchRunner::class,
            ],
        ];
    }

    /** Nothing here needs configuring and nothing here costs money, so nothing is asked first. */
    protected function afterInstall(): void
    {
        parent::afterInstall();

        $this->engine()->queueSync(null);
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->user->getIsAdmin()) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = 'Smart Search';

        $subNav = [];

        $subNav['dashboard'] = ['label' => 'Dashboard', 'url' => 'smart-search'];
        if ($this->historyService->hasAny()) {
            $subNav['insights'] = ['label' => 'Insights', 'url' => 'smart-search/insights'];
        }
        $subNav['index'] = ['label' => 'Index', 'url' => 'smart-search/index'];

        if ($this->engine()->hasIndex()) {
            $subNav['preview'] = ['label' => 'Preview', 'url' => 'smart-search/preview'];
        }

        $subNav['settings'] = ['label' => 'Settings', 'url' => 'smart-search/settings'];

        $item['subnav'] = $subNav;

        return $item;
    }

    public function getSettingsResponse(): Response
    {
        return Craft::$app->controller->redirect(
            UrlHelper::cpUrl('smart-search')
        );
    }

    public function getReadOnlySettingsResponse(): Response
    {
        return $this->getSettingsResponse();
    }

    /**
     * Removes the entries of sections turned off and queues the sections turned on.
     *
     * @param list<int> $before
     * @param list<int> $after
     */
    private function queueSectionChanges(array $before, array $after): void
    {
        $off = array_values(array_diff($before, $after));
        $on = array_values(array_diff($after, $before));

        if ($off === [] && $on === []) {
            return;
        }

        if ($off !== []) {
            foreach (Craft::$app->getSites()->getAllSiteIds(true) as $siteId) {
                $entryIds = array_map('intval', Entry::find()->sectionId($off)->siteId($siteId)->status(null)->ids());
                $this->engine()->queueDelete($entryIds, (int)$siteId);
            }
        }

        foreach ($on as $sectionId) {
            $this->engine()->queueSync(null, Craft::$app->getEntries()->getSectionById($sectionId)->handle);
        }

        $this->indexInspectionService->invalidateCoverage();
        CacheTag::invalidateResults();
        Logger::info('Indexed sections changed', ['off' => $off, 'on' => $on]);
    }

    private function attachEventHandlers(): void
    {
        $queueIndex = function(ElementEvent $event): void {
            $element = $event->element;

            if ($element instanceof Entry &&
                !$element->getIsDraft() &&
                !$element->getIsRevision() &&
                $element->sectionId !== null) {
                $this->engine()->queueIndex($element);
            }
        };

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $queueIndex);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $queueIndex);

        // A deleted site's rows would otherwise stay in the index and count in every stat.
        Event::on(Sites::class, Sites::EVENT_AFTER_DELETE_SITE, function(DeleteSiteEvent $event): void {
            $this->engine()->clearAll((int)$event->site->id);
            $this->exclusionService->clearSite((int)$event->site->id);
        });

        // The keyword index stores stems in the site's language, so a language change leaves
        // it matching nothing until a resync. Hooked on project config so deploys count too.
        Craft::$app->getProjectConfig()->onUpdate(
            ProjectConfig::PATH_SITES . '.{uid}',
            function(ConfigEvent $event): void {
                if ($event->oldValue === null || ($event->oldValue['language'] ?? null) === ($event->newValue['language'] ?? null)) {
                    return;
                }

                $this->engine()->queueSync(Craft::$app->getSites()->getSiteByUid($event->tokenMatches[0], true)->id);
            }
        );

        // Turning sections on or off, from the Indexing tab or a deploy.
        Craft::$app->getProjectConfig()->onUpdate(
            ProjectConfig::PATH_PLUGINS . '.' . $this->handle . '.settings',
            function(ConfigEvent $event): void {
                $this->queueSectionChanges(
                    Settings::sectionIdsFor(ProjectConfigHelper::unpackAssociativeArrays($event->oldValue)['indexedSections'] ?? []),
                    Settings::sectionIdsFor(ProjectConfigHelper::unpackAssociativeArrays($event->newValue)['indexedSections'] ?? []),
                );
            }
        );

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function(ElementEvent $event) {
                $element = $event->element;
                if ($element instanceof Entry) {
                    $this->engine()->queueDelete([(int)$element->id], (int)$element->siteId);
                }
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['smart-search'] = 'smart-search/dashboard/index';

                $event->rules['smart-search/settings'] = 'smart-search/settings/index';
                $event->rules['smart-search/settings/<tab:[a-z-]+>'] = 'smart-search/settings/index';

                $event->rules['smart-search/index'] = 'smart-search/index/index';
                $event->rules['smart-search/index/entry'] = 'smart-search/index/entry';

                $event->rules['smart-search/insights'] = 'smart-search/insights/index';

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
        $this->registerGqlHandlers();
    }

    /** Smart Search's own queries on Craft's GraphQL endpoint, gated per token. */
    private function registerGqlHandlers(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function(RegisterGqlQueriesEvent $event) {
                $event->queries = array_merge($event->queries, SmartSearchGql::queries());
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            function(RegisterGqlSchemaComponentsEvent $event) {
                $event->queries[Craft::t('smart-search', 'Smart Search')] = SmartSearchGql::schemaComponents();
            }
        );
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
