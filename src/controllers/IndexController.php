<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use ghoststreet\craftsmartsearch\exceptions\DatabaseException;
use ghoststreet\craftsmartsearch\helpers\ErrorMapper;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\jobs\IndexEntryJob;
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
        $setup['ready'] = $setup['credentials'] && $setup['connection'] && $setup['schema'] && $setup['openaiKey'];

        $syncJobs = $this->loadSyncJobs();
        $overview = $this->buildOverviewData($setup, $stats, $syncJobs['perSite']);
        $syncStarted = Craft::$app->getSession()->getFlash('smart-search-sync-started', false)
            || !empty($syncJobs['all']);

        return $this->renderTemplate('smart-search/index-mgmt/index', array_merge($this->commonViewData(), [
            'setup' => $setup,
            'overview' => $overview,
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
            $error = ErrorMapper::present($e, 'getEntryRows', ['siteId' => $filters['siteId']]);
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

    public function actionCoverage(): Response
    {
        $this->requireAdmin();
        if (($redirect = $this->redirectIfNotConfigured()) !== null) {
            return $redirect;
        }

        $common = $this->commonViewData();
        if (!$common['isMultiSite']) {
            return $this->redirect('smart-search/index');
        }

        $plugin = SmartSearch::getInstance();
        try {
            $coverage = $plugin->indexInspectionService->getCoverageBySite();
            $error = null;
        } catch (DatabaseException $e) {
            $coverage = [];
            $error = ErrorMapper::present($e, 'getCoverageBySite', []);
        }

        return $this->renderTemplate('smart-search/index-mgmt/coverage', array_merge($common, [
            'coverage' => $coverage,
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
            'isMultiSite' => count(Craft::$app->getSites()->getAllSites()) > 1,
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
            $error = ErrorMapper::present($e, 'inspectElement', ['elementId' => $elementId, 'siteId' => $siteId]);
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
                    'error' => ErrorMapper::present($e, 'sync'),
                ]);
            }
            Craft::$app->getSession()->setError(
                Craft::t('smart-search', 'Failed to start sync: {error}', ['error' => ErrorMapper::present($e, 'sync')])
            );
        }

        return $this->redirect('smart-search/index');
    }

    public function actionGetStats(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        try {
            $plugin = SmartSearch::getInstance();

            [$stats, $perSite, $coverage] = Craft::$app->getCache()->getOrSet(
                'smart_search_index_stats',
                function () use ($plugin): array {
                    $perSite = $this->loadPerSiteStats();
                    return [
                        $plugin->databaseService->getStats(false),
                        $perSite,
                        $this->buildCoverageRows($perSite, /* withLabel */ true),
                    ];
                },
                self::STATS_CACHE_TTL
            );

            $jobs = $this->loadSyncJobs();

            return $this->asJson([
                'success' => true,
                'entryCount' => $stats['entryCount'],
                'chunkCount' => $stats['chunkCount'],
                'perSite' => $perSite,
                'coverage' => $coverage,
                'jobs' => array_values($jobs['perSite']),
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
     * Merge entry-side coverage (indexed / stale / notIndexed / total) with
     * the vector-side `lastIndexed` timestamp. When $withLabel is true also
     * formats `lastIndexedLabel` for direct display.
     *
     * @param list<array{siteId: int, entryCount: int, chunkCount: int, lastIndexed: ?string}> $perSiteStats
     * @return list<array<string, mixed>>
     */
    private function buildCoverageRows(array $perSiteStats, bool $withLabel = false): array
    {
        $statsBySite = [];
        foreach ($perSiteStats as $row) {
            $statsBySite[$row['siteId']] = $row;
        }
        $coverage = SmartSearch::getInstance()->indexInspectionService->getCoverageBySite();
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
     * Scan the queue for in-flight SyncSearchIndexJob entries. Detection is
     * locale-proof — we filter on the serialized job class, not on the
     * (translated) description string.
     *
     * @return array{perSite: array<int, array<string, mixed>>, global: ?array<string, mixed>, all: list<array<string, mixed>>}
     */
    private function loadSyncJobs(): array
    {
        $out = ['perSite' => [], 'global' => null, 'all' => []];
        try {
            $rows = (new Query())
                ->select(['id', 'job'])
                ->from(Table::QUEUE)
                ->where(['like', 'job', SyncSearchIndexJob::class])
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
                    $siteIdsById[$row['id']] = ($job instanceof SyncSearchIndexJob) ? $job->siteId : null;
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

        $perSiteStats = $this->loadPerSiteStats();
        $coverageRows = $this->buildCoverageRows($perSiteStats);
        $coverageBySite = [];
        foreach ($coverageRows as $row) {
            $coverageBySite[$row['siteId']] = $row;
        }
        $rows = [];
        foreach ($sites as $site) {
            $sid = (int)$site->id;
            $cov = $coverageBySite[$sid] ?? ['indexed' => 0, 'stale' => 0, 'notIndexed' => 0, 'total' => 0, 'lastIndexed' => null, 'chunkCount' => 0];
            $rows[] = [
                'siteId' => $sid,
                'name' => $site->name ?: $site->handle,
                'handle' => $site->handle,
                'indexed' => $cov['indexed'],
                'stale' => $cov['stale'],
                'notIndexed' => $cov['notIndexed'],
                'total' => $cov['total'],
                'chunkCount' => $cov['chunkCount'],
                'lastIndexed' => $cov['lastIndexed'],
                'activeJob' => $activeJobs[$sid] ?? null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $pctA = $a['total'] > 0 ? $a['indexed'] / $a['total'] : 0;
            $pctB = $b['total'] > 0 ? $b['indexed'] / $b['total'] : 0;
            return $pctB <=> $pctA ?: $b['indexed'] <=> $a['indexed'] ?: strcasecmp($a['name'], $b['name']);
        });

        return [
            'state' => 'ready',
            'error' => null,
            'sites' => $rows,
            'isMultiSite' => $isMultiSite,
        ];
    }

    public function actionCancelSync(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $rawSiteId = Craft::$app->getRequest()->getBodyParam('siteId');
        $siteId = ($rawSiteId !== null && $rawSiteId !== '') ? (int)$rawSiteId : null;

        $queue = Craft::$app->getQueue();
        $released = 0;

        foreach ($this->loadSyncJobs()['all'] as $job) {
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

        Logger::info('Cancelled sync', ['released' => $released, 'siteId' => $siteId]);

        return $this->asJson(['success' => true, 'released' => $released]);
    }

    public function actionReindexEntry(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');

        $jobId = Craft::$app->getQueue()->push(new IndexEntryJob([
            'entryId' => $elementId,
            'siteId' => $siteId,
        ]));

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'jobId' => (string)$jobId,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('smart-search', 'Re-index queued for entry #{id}.', ['id' => $elementId]));

        return $this->redirectToPostedUrl();
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

        $jobId = Craft::$app->getQueue()->push(new IndexEntryJob([
            'entryId' => $elementId,
            'siteId' => $siteId,
        ]));

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'jobId' => (string)$jobId,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('smart-search', 'Entry #{id} re-included in index.', ['id' => $elementId]));

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
        $done = $status === 3 || $status >= 4;

        return $this->asJson([
            'success' => true,
            'jobId' => $jobId,
            'status' => $status,
            'done' => $done,
        ]);
    }
}
