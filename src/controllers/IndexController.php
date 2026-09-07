<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\queue\Queue;
use ghoststreet\craftsmartsearch\exceptions\DatabaseException;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\jobs\IndexEntryJob;
use ghoststreet\craftsmartsearch\jobs\LocalSyncIndexJob;
use ghoststreet\craftsmartsearch\jobs\SyncSearchIndexJob;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\i18n\Formatter;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Index management page. Three tabs: overview (with the sync trigger button),
 * entries, and coverage.
 */
class IndexController extends BaseApiController
{
    protected array|int|bool $allowAnonymous = false;

    /** Seconds the polled stats snapshot (totals + per-site + coverage) is cached. */
    private const STATS_CACHE_TTL = 5;

    public function actionIndex(): Response
    {
        $this->requireAdmin();
        if (($redirect = $this->redirectIfNotConfigured()) !== null) {
            return $redirect;
        }

        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();
        $stats = $plugin->databaseService->getStatsSafe();

        $hasCredentials = !empty($settings->getPostgresqlHost())
            && !empty($settings->getPostgresqlDatabase())
            && !empty($settings->getPostgresqlUser())
            && !empty($settings->getPostgresqlPassword());

        $setup = [
            'credentials' => $hasCredentials,
            'connection' => (bool)($stats['isConnected'] ?? false),
            'schema' => ($stats['isConnected'] ?? false) ? $plugin->databaseService->isSchemaInitialized() : false,
            'openaiKey' => !empty($settings->getOpenaiApiKey()),
            'error' => $stats['error'] ?? null,
        ];

        $syncJobs = $this->loadSyncJobs();
        $overview = $this->buildOverviewData($setup, $stats, $syncJobs['perSite']);
        $syncStarted = Craft::$app->getSession()->getFlash('smart-search-sync-started', false)
            || !empty($syncJobs['all']);

        return $this->renderTemplate('smart-search/index-mgmt/index', array_merge($this->commonViewData(), [
            'setupSteps' => $this->buildSetupSteps($setup),
            'overview' => $overview,
            'localSites' => $this->buildLocalOverview(),
            'syncStarted' => $syncStarted,
        ]));
    }

    public function actionEntries(): Response
    {
        $this->requireAdmin();
        if (($redirect = $this->redirectIfNotConfigured()) !== null) {
            return $redirect;
        }

        $request = Craft::$app->getRequest();
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sectionParam = $request->getQueryParam('section') ?: null;
        $statusParam = $request->getQueryParam('status') ?: null;
        $siteIdParam = (int)($request->getQueryParam('siteId') ?: $currentSiteId);
        $filters = [
            'section' => $sectionParam,
            'siteId' => $siteIdParam,
            'status' => $statusParam,
            'page' => (int)($request->getQueryParam('page') ?: 1),
        ];

        $plugin = SmartSearch::getInstance();
        try {
            $result = $plugin->indexInspectionService->getEntryRows($filters);
            $error = null;
        } catch (DatabaseException $e) {
            $result = ['rows' => [], 'total' => 0, 'page' => 1, 'pageSize' => 25, 'counts' => ['indexed' => 0, 'stale' => 0, 'not-indexed' => 0, 'total' => 0]];
            $error = $this->presentError($e, 'getEntryRows', ['siteId' => $filters['siteId']]);
        }

        return $this->renderTemplate('smart-search/index-mgmt/entries', array_merge($this->commonViewData(), [
            'filters' => $filters,
            'hasActiveFilters' => (bool)($sectionParam || $statusParam || $siteIdParam !== $currentSiteId),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'result' => $result,
            'error' => $error,
        ]));
    }

    private function commonViewData(): array
    {
        $plugin = SmartSearch::getInstance();
        return [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'selectedSubnavItem' => 'index',
        ];
    }

    private function redirectIfNotConfigured(): ?Response
    {
        $settings = SmartSearch::getInstance()->getSettings();
        if (empty($settings->getOpenaiApiKey())
            || empty($settings->getPostgresqlHost())
            || empty($settings->getPostgresqlDatabase())) {
            return $this->redirect('smart-search');
        }
        return null;
    }

    public function actionEntry(): Response
    {
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredQueryParam('elementId');
        $siteId = (int)$request->getRequiredQueryParam('siteId');

        $plugin = SmartSearch::getInstance();

        try {
            $inspection = $plugin->indexInspectionService->inspectElement($elementId, $siteId);
            $error = null;
        } catch (DatabaseException $e) {
            $inspection = null;
            $error = $this->presentError($e, 'inspectElement', ['elementId' => $elementId, 'siteId' => $siteId]);
        }

        if ($inspection === null && $error === null) {
            throw new NotFoundHttpException('Entry not found');
        }

        return $this->renderTemplate('smart-search/index-mgmt/entry', [
            'plugin' => $plugin,
            'inspection' => $inspection,
            'error' => $error,
            'selectedSubnavItem' => 'index',
        ]);
    }

    public function actionSync(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $rawSiteId = $request->getBodyParam('siteId');
        $siteId = ($rawSiteId !== null && $rawSiteId !== '') ? (int)$rawSiteId : null;

        try {
            Logger::info('Starting incremental sync', ['siteId' => $siteId]);

            $query = Entry::find()
                ->siteId($siteId ?? '*')
                ->unique(false)
                ->status(Entry::STATUS_ENABLED)
                ->uri(':notempty:')
                ->select(['elements.id', 'elements_sites.siteId'])
                ->asArray();

            $entries = $query->all();

            $activeKeys = [];
            foreach ($entries as $entry) {
                $activeKeys[] = [
                    'elementId' => (int)$entry['id'],
                    'siteId' => (int)$entry['siteId'],
                ];
            }

            $orphans = SmartSearch::getInstance()->databaseService->deleteOrphanedVectors($activeKeys, $siteId);

            if ($siteId !== null) {
                Craft::$app->getQueue()->push(new SyncSearchIndexJob(['siteId' => $siteId]));
            } else {
                foreach (Craft::$app->getSites()->getAllSites() as $site) {
                    Craft::$app->getQueue()->push(new SyncSearchIndexJob(['siteId' => (int)$site->id]));
                }
            }

            $count = count($activeKeys);
            Logger::info('Queued sync job', ['entries' => $count, 'orphansRemoved' => $orphans, 'siteId' => $siteId]);

            Craft::$app->getSession()->setFlash('smart-search-sync-started', true);

            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson([
                    'success' => true,
                    'queued' => $count,
                    'orphansRemoved' => $orphans,
                ]);
            }

            Craft::$app->getSession()->setNotice(
                Craft::t('smart-search', 'Sync queued for {count} entries. {orphans} orphaned vectors removed.', [
                    'count' => $count,
                    'orphans' => $orphans,
                ])
            );
        } catch (DatabaseException $e) {
            if (Craft::$app->getRequest()->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'error' => $this->presentError($e, 'sync'),
                ]);
            }
            Craft::$app->getSession()->setError(
                Craft::t('smart-search', 'Failed to start sync: {error}', ['error' => $this->presentError($e, 'sync')])
            );
        }

        return $this->redirect('smart-search/index');
    }

    /**
     * Queue a bulk reindex of the local store, the twin of actionSync above.
     *
     * Separate from the pgvector sync rather than folded into it, because the two stores
     * are rebuilt independently: that is the whole reason LocalSyncIndexJob exists as its
     * own job. Saving an entry already queues both, so only the bulk path was missing,
     * and a CP-only workflow could rebuild pgvector while leaving local on stale data
     * with nothing on screen to say so.
     *
     * No orphan sweep here. The pgvector sync prunes vectors for entries that have gone,
     * but the local store holds exactly the same expired entries by the same enumeration,
     * so pruning one side and not the other would invent a difference between the arms.
     */
    public function actionLocalSync(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        if (!SmartSearch::getInstance()->getSettings()->localEnabled) {
            Craft::$app->getSession()->setError(
                Craft::t('smart-search', 'Local search is off. Enable it under Settings > Local first.')
            );
            return $this->redirect('smart-search');
        }

        $rawSiteId = Craft::$app->getRequest()->getBodyParam('siteId');
        $siteId = ($rawSiteId !== null && $rawSiteId !== '') ? (int)$rawSiteId : null;

        $sites = $siteId !== null
            ? [$siteId]
            : array_map(static fn($site): int => (int)$site->id, Craft::$app->getSites()->getAllSites());

        foreach ($sites as $id) {
            Craft::$app->getQueue()->push(new LocalSyncIndexJob(['siteId' => $id]));
        }

        Logger::info('Queued local sync job', ['sites' => count($sites), 'siteId' => $siteId]);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'sites' => count($sites)]);
        }

        Craft::$app->getSession()->setNotice(
            Craft::t('smart-search', 'Local reindex queued for {count} site(s). Unchanged entries are skipped.', [
                'count' => count($sites),
            ])
        );

        /* Back where it was pressed: the button exists on both the dashboard meter and
           the Index page, and landing somewhere else is disorienting either way. */
        return $this->redirect(Craft::$app->getRequest()->getReferrer() ?? 'smart-search/index');
    }

    public function actionGetStats(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        try {
            $plugin = SmartSearch::getInstance();

            [$stats, $perSite, $coverage] = Craft::$app->getCache()->getOrSet(
                'smart_search_index_stats',
                function() use ($plugin): array {
                    $perSite = $this->loadPerSiteStats();
                    return [
                        $plugin->databaseService->getStats(false),
                        $perSite,
                        $this->buildCoverageRows($perSite, /* withLabel */ true),
                    ];
                },
                self::STATS_CACHE_TTL,
                CacheTag::dependency(),
            );

            $jobs = $this->loadSyncJobs();
            $localJobs = $this->loadSyncJobs(LocalSyncIndexJob::class);

            /* Local rows are not cached alongside the pgvector block above: they are a
               handful of grouped queries against Craft's own database, and caching them
               would make a card sit on stale counts for the whole TTL right after the
               reindex the user just triggered. */
            return $this->asJson([
                'success' => true,
                'entryCount' => $stats['entryCount'],
                'chunkCount' => $stats['chunkCount'],
                'perSite' => $perSite,
                'coverage' => $coverage,
                'jobs' => array_values($jobs['perSite']),
                'localCoverage' => $this->buildLocalOverview(/* withLabel */ true),
                'localJobs' => array_values($localJobs['perSite']),
                'queueRemaining' => Craft::$app->getQueue()->getTotalWaiting(),
                'sync' => $jobs['global'],
            ]);
        } catch (Throwable $e) {
            return $this->jsonError($e, 'getStats');
        }
    }

    // -----------------------------------------------------------------------
    // Overview helpers — shared between actionIndex (server-side render)
    // and actionGetStats (JSON polling endpoint).
    // -----------------------------------------------------------------------

    /**
     * Vectors-table per-site stats, or [] if the query fails.
     *
     * @return list<array{siteId: int, entryCount: int, chunkCount: int, lastIndexed: ?string}>
     */
    private function loadPerSiteStats(): array
    {
        try {
            return SmartSearch::getInstance()->databaseService->getStatsPerSite();
        } catch (DatabaseException) {
            return [];
        }
    }

    /**
     * The local store's per-site stats, reshaped to the same keys the pgvector rows use
     * so buildCoverageRows does not need to know which store it is merging.
     *
     * @return list<array{siteId: int, entryCount: int, chunkCount: int, lastIndexed: ?string}>
     */
    private function loadLocalPerSiteStats(): array
    {
        try {
            $stats = SmartSearch::getInstance()->localIndexService->stats();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($stats['sites'] ?? [] as $row) {
            $out[] = [
                'siteId' => (int)$row['siteId'],
                'entryCount' => (int)($row['entries'] ?? 0),
                'chunkCount' => (int)($row['chunks'] ?? 0),
                'lastIndexed' => $row['lastIndexed'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * One store's worth of overview cards: coverage merged with per-site stats and any
     * job currently in the queue for it.
     *
     * @return list<array<string, mixed>>
     */
    private function buildStoreRows(string $store, array $activeJobs, bool $withLabel = false): array
    {
        $perSite = $store === 'local' ? $this->loadLocalPerSiteStats() : $this->loadPerSiteStats();
        $bySite = array_column($this->buildCoverageRows($perSite, $withLabel, $store), null, 'siteId');

        $rows = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $sid = (int)$site->id;
            $cov = $bySite[$sid] ?? [
                'indexed' => 0, 'stale' => 0, 'notIndexed' => 0, 'total' => 0,
                'lastIndexed' => null, 'chunkCount' => 0,
            ];
            $rows[] = [
                'store' => $store,
                'siteId' => $sid,
                'name' => $site->name ?: $site->handle,
                'handle' => $site->handle,
                'indexed' => $cov['indexed'],
                'stale' => $cov['stale'],
                'notIndexed' => $cov['notIndexed'],
                'total' => $cov['total'],
                'chunkCount' => $cov['chunkCount'],
                'lastIndexed' => $cov['lastIndexed'],
                'lastIndexedLabel' => $cov['lastIndexedLabel'] ?? null,
                'activeJob' => $activeJobs[$sid] ?? null,
            ];
        }

        usort($rows, static function(array $a, array $b): int {
            $pctA = $a['total'] > 0 ? $a['indexed'] / $a['total'] : 0;
            $pctB = $b['total'] > 0 ? $b['indexed'] / $b['total'] : 0;
            return $pctB <=> $pctA ?: $b['indexed'] <=> $a['indexed'] ?: strcasecmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * Merge entry-side coverage (indexed / stale / notIndexed / total) with
     * the vector-side `lastIndexed` timestamp. When $withLabel is true also
     * formats `lastIndexedLabel` for direct display.
     *
     * @param list<array{siteId: int, entryCount: int, chunkCount: int, lastIndexed: ?string}> $perSiteStats
     * @return list<array<string, mixed>>
     */
    private function buildCoverageRows(array $perSiteStats, bool $withLabel = false, string $store = 'pgvector'): array
    {
        $statsBySite = array_column($perSiteStats, null, 'siteId');
        $inspection = SmartSearch::getInstance()->indexInspectionService;
        $coverage = $store === 'local'
            ? $inspection->getLocalCoverageBySite()
            : $inspection->getCoverageBySite();
        $formatter = $withLabel ? Craft::$app->getFormatter() : null;

        $out = [];
        foreach ($coverage as $row) {
            $sid = $row['siteId'];
            $lastIso = $statsBySite[$sid]['lastIndexed'] ?? null;
            $row['lastIndexed'] = $lastIso;
            if ($formatter !== null) {
                $row['lastIndexedLabel'] = $lastIso
                    ? $formatter->asDatetime($lastIso, Formatter::FORMAT_WIDTH_SHORT)
                    : null;
            }
            $row['chunkCount'] = $statsBySite[$sid]['chunkCount'] ?? 0;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Scan the queue for in-flight sync jobs of one class. Detection is
     * locale-proof — we filter on the serialized job class, not on the
     * (translated) description string.
     *
     * Taking the class as an argument is what lets the local store reuse this: the two
     * jobs are separate classes precisely so one can run without the other, and the queue
     * is the only place that distinction is visible.
     *
     * @param class-string $jobClass
     * @return array{perSite: array<int, array<string, mixed>>, global: ?array<string, mixed>, all: list<array<string, mixed>>}
     */
    private function loadSyncJobs(string $jobClass = SyncSearchIndexJob::class): array
    {
        $out = ['perSite' => [], 'global' => null, 'all' => []];
        try {
            $rows = (new Query())
                ->select(['id', 'job'])
                ->from(Table::QUEUE)
                ->where(['like', 'job', $jobClass])
                ->all();
            if (empty($rows)) {
                return $out;
            }

            $queue = Craft::$app->getQueue();
            $siteIdsById = [];
            foreach ($rows as $row) {
                try {
                    $payload = is_resource($row['job']) ? stream_get_contents($row['job']) : (string)$row['job'];
                    $job = $queue->serializer->unserialize($payload);
                    $siteIdsById[$row['id']] = ($job instanceof $jobClass) ? $job->siteId : null;
                } catch (Throwable) {
                    $siteIdsById[$row['id']] = null;
                }
            }

            foreach ($queue->getJobInfo(100) as $info) {
                if (!array_key_exists($info['id'], $siteIdsById)) {
                    continue;
                }
                $siteId = $siteIdsById[$info['id']];
                $entry = [
                    'id' => $info['id'],
                    'siteId' => $siteId,
                    'description' => (string)$info['description'],
                    'progress' => (int)$info['progress'],
                    'progressLabel' => $info['progressLabel'] ?? null,
                    'status' => (int)$info['status'],
                    'error' => $info['error'] ?? null,
                ];
                $out['all'][] = $entry;
                if ($siteId !== null) {
                    $out['perSite'][$siteId] = $entry;
                } elseif ($out['global'] === null) {
                    $out['global'] = $entry;
                }
            }
        } catch (Throwable $e) {
            // Queue inspection failures shouldn't block rendering.
        }
        return $out;
    }

    /**
     * Diagnostic checklist for the Overview tab's onboarding state. Each hint only
     * fires once the step before it passes, so one broken link in the chain doesn't
     * light up every row below it.
     *
     * @param array $setup Flags from actionIndex: credentials, connection, schema, error.
     */
    private function buildSetupSteps(array $setup): array
    {
        $postgresUrl = UrlHelper::cpUrl('smart-search/settings/connections/postgres');

        return [
            [
                'done' => $setup['credentials'],
                'label' => 'Configure PostgreSQL credentials',
                'hint' => $setup['credentials'] ? null : 'Host, database, user, and password are all required.',
                'url' => $postgresUrl,
            ],
            [
                'done' => $setup['connection'],
                'label' => 'Connect to the vector database',
                'hint' => ($setup['credentials'] && !$setup['connection']) ? $setup['error'] : null,
                'url' => $postgresUrl,
            ],
            [
                'done' => $setup['schema'],
                'label' => 'Initialize the vector schema',
                'hint' => ($setup['connection'] && !$setup['schema'])
                    ? 'The vectors table was not found. Run the setup SQL from the README.'
                    : null,
                'url' => $postgresUrl,
            ],
        ];
    }

    /**
     * Build the data needed by the Overview tab template. Decides between three
     * mutually-exclusive states (onboarding / disconnected / ready) and assembles
     * per-site cards from the vectors-side counters and entry-side coverage.
     *
     * @param array $activeJobs In-flight sync jobs by siteId (loadSyncJobs()['perSite']), painted into the "Indexing" state.
     */
    private function buildOverviewData(array $setup, array $stats, array $activeJobs): array
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $isMultiSite = count($sites) > 1;

        if (!$setup['credentials'] || !$setup['schema'] || !$setup['openaiKey']) {
            return ['state' => 'onboarding', 'error' => null, 'sites' => [], 'isMultiSite' => $isMultiSite];
        }
        if (!$setup['connection']) {
            return ['state' => 'disconnected', 'error' => $stats['error'] ?? null, 'sites' => [], 'isMultiSite' => $isMultiSite];
        }

        return [
            'state' => 'ready',
            'error' => null,
            'sites' => $this->buildStoreRows('pgvector', $activeJobs),
            'isMultiSite' => $isMultiSite,
        ];
    }

    /**
     * Local cards, built whether or not pgvector is healthy.
     *
     * They are returned separately from buildOverviewData rather than inside it because
     * that method short-circuits to onboarding or disconnected on pgvector's state, and
     * the local store depends on neither PostgreSQL nor the OpenAI key at search time.
     * Hiding its cards when the other index is down would withhold them exactly when
     * they matter.
     *
     * @return list<array<string, mixed>>
     */
    private function buildLocalOverview(bool $withLabel = false): array
    {
        if (!SmartSearch::getInstance()->getSettings()->localEnabled) {
            return [];
        }
        return $this->buildStoreRows('local', $this->loadSyncJobs(LocalSyncIndexJob::class)['perSite'], $withLabel);
    }

    public function actionCancelSync(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $rawSiteId = $request->getBodyParam('siteId');
        $siteId = ($rawSiteId !== null && $rawSiteId !== '') ? (int)$rawSiteId : null;

        /* Which store's job to release. Without this a Cancel on a local card would
           release the pgvector sync running beside it. */
        $jobClass = $request->getBodyParam('store') === 'local'
            ? LocalSyncIndexJob::class
            : SyncSearchIndexJob::class;

        $queue = Craft::$app->getQueue();
        $released = 0;

        foreach ($this->loadSyncJobs($jobClass)['all'] as $job) {
            if ($siteId !== null && $job['siteId'] !== $siteId) {
                continue;
            }
            try {
                $queue->release((string)$job['id']);
                $released++;
            } catch (Throwable $e) {
                Logger::exception($e, 'cancelSync', ['jobId' => $job['id']]);
            }
        }

        Logger::info('Cancelled sync', ['released' => $released, 'siteId' => $siteId, 'job' => $jobClass]);

        return $this->asJson(['success' => true, 'released' => $released]);
    }

    public function actionReindexEntry(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');

        return $this->queueIndexJob(
            $elementId,
            $siteId,
            Craft::t('smart-search', 'Re-index queued for entry #{id}.', ['id' => $elementId])
        );
    }

    public function actionExcludeEntry(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');

        SmartSearch::getInstance()->exclusionService->exclude($elementId, $siteId);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('smart-search', 'Entry #{id} excluded from index.', ['id' => $elementId]));

        return $this->redirectToPostedUrl();
    }

    public function actionIncludeEntry(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');

        SmartSearch::getInstance()->exclusionService->include($elementId, $siteId);

        return $this->queueIndexJob(
            $elementId,
            $siteId,
            Craft::t('smart-search', 'Entry #{id} re-included in index.', ['id' => $elementId])
        );
    }

    /** Push an IndexEntryJob, then answer JSON (with jobId) or redirect with a notice. */
    private function queueIndexJob(int $elementId, int $siteId, string $notice): Response
    {
        $jobId = Craft::$app->getQueue()->push(new IndexEntryJob([
            'entryId' => $elementId,
            'siteId' => $siteId,
        ]));

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'jobId' => (string)$jobId]);
        }

        Craft::$app->getSession()->setNotice($notice);

        return $this->redirectToPostedUrl();
    }

    public function actionEntryState(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredQueryParam('elementId');
        $siteId = (int)$request->getRequiredQueryParam('siteId');

        try {
            $summary = SmartSearch::getInstance()->databaseService->getIndexedSummary($siteId);
        } catch (DatabaseException $e) {
            return $this->jsonError($e, 'entryState');
        }

        $row = $summary[$elementId . '-' . $siteId] ?? null;

        return $this->asJson([
            'success' => true,
            'chunkCount' => $row['chunkCount'] ?? 0,
            'lastIndexed' => ($row !== null && !empty($row['lastIndexed']))
                ? Craft::$app->getFormatter()->asDatetime($row['lastIndexed'], Formatter::FORMAT_WIDTH_SHORT)
                : null,
        ]);
    }

    public function actionJobStatus(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        $jobId = (string)Craft::$app->getRequest()->getRequiredQueryParam('id');
        $status = Craft::$app->getQueue()->status($jobId);
        $done = $status >= Queue::STATUS_DONE;

        return $this->asJson([
            'success' => true,
            'jobId' => $jobId,
            'status' => $status,
            'done' => $done,
        ]);
    }
}
