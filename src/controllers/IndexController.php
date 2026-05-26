<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\elements\Entry;
use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\exceptions\DatabaseException;
use ghoststreet\craftsmartsearch\helpers\ErrorMapper;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\jobs\IndexEntryJob;
use ghoststreet\craftsmartsearch\jobs\SyncSearchIndexJob;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Index management page: tabs for overview/sync, entries, and coverage.
 */
class IndexController extends BaseApiController
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $plugin = SmartSearch::getInstance();
        $settings = $plugin->getSettings();

        if (empty($settings->getOpenaiApiKey())
            || empty($settings->getPostgresqlHost())
            || empty($settings->getPostgresqlDatabase())) {
            return $this->redirect('smart-search');
        }

        $request = Craft::$app->getRequest();
        $tab = $request->getQueryParam('tab') ?: 'overview';
        $isMultiSite = count(Craft::$app->getSites()->getAllSites()) > 1;
        if ($tab === 'coverage' && !$isMultiSite) {
            $tab = 'overview';
        }

        $data = [
            'tab' => $tab,
            'plugin' => $plugin,
            'settings' => $settings,
            'selectedSubnavItem' => 'index',
            'isMultiSite' => $isMultiSite,
        ];

        if ($tab === 'entries') {
            $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
            $sectionParam = $request->getQueryParam('section') ?: null;
            $statusParam = $request->getQueryParam('status') ?: null;
            $siteIdParam = (int)($request->getQueryParam('siteId') ?: $currentSiteId);
            $data['filters'] = [
                'section' => $sectionParam,
                'siteId' => $siteIdParam,
                'status' => $statusParam,
                'page' => (int)($request->getQueryParam('page') ?: 1),
            ];
            $data['hasActiveFilters'] = (bool)($sectionParam || $statusParam || $siteIdParam !== $currentSiteId);
            $data['sections'] = Craft::$app->getEntries()->getAllSections();
            $data['sites'] = Craft::$app->getSites()->getAllSites();
        } elseif ($tab === 'coverage') {
            // coverage data is loaded via AJAX (actionGetCoverage)
        }

        return $this->renderTemplate('smart-search/index-mgmt/index', $data);
    }

    public function actionGetEntryRows(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $filters = [
            'section' => $request->getQueryParam('section') ?: null,
            'siteId' => (int)($request->getQueryParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id),
            'status' => $request->getQueryParam('status') ?: null,
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

        $html = Craft::$app->getView()->renderTemplate('smart-search/_partials/entries-content', [
            'result' => $result,
            'filters' => $filters,
            'error' => $error,
        ]);

        return $this->asJson(['success' => true, 'html' => $html]);
    }

    public function actionGetOverview(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

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

        $overview = $this->buildOverviewData($setup, $stats);
        $syncStarted = Craft::$app->getSession()->getFlash('smart-search-sync-started', false)
            || $this->hasActiveSyncJob();

        $html = Craft::$app->getView()->renderTemplate('smart-search/_partials/overview-content', [
            'setup' => $setup,
            'overview' => $overview,
            'syncStarted' => $syncStarted,
        ]);

        return $this->asJson(['success' => true, 'html' => $html]);
    }

    public function actionGetCoverage(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        $plugin = SmartSearch::getInstance();

        try {
            $coverage = $plugin->indexInspectionService->getCoverageBySite();
            $error = null;
        } catch (DatabaseException $e) {
            $coverage = [];
            $error = ErrorMapper::present($e, 'getCoverageBySite', []);
        }

        $html = Craft::$app->getView()->renderTemplate('smart-search/_partials/coverage-content', [
            'coverage' => $coverage,
            'error' => $error,
        ]);

        return $this->asJson(['success' => true, 'html' => $html]);
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
            $stats = $plugin->databaseService->getStats(false);
            $perSite = $this->loadPerSiteStats();
            $coverage = $this->buildCoverageRows($perSite, /* withLabel */ true);
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
        } catch (\Throwable $e) {
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
                    ? $formatter->asDatetime($lastIso, \yii\i18n\Formatter::FORMAT_WIDTH_SHORT)
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
            $idRows = (new \craft\db\Query())
                ->select(['id'])
                ->from(\craft\db\Table::QUEUE)
                ->where(['like', 'job', SyncSearchIndexJob::class])
                ->column();
            if (empty($idRows)) {
                return $out;
            }
            $ids = array_fill_keys($idRows, true);

            foreach (Craft::$app->getQueue()->getJobInfo(100) as $info) {
                if (!isset($ids[$info['id']])) {
                    continue;
                }
                $description = (string)$info['description'];
                $siteId = null;
                if (preg_match('/\[site:(\d+)\]/', $description, $m)) {
                    $siteId = (int)$m[1];
                }
                $entry = [
                    'id' => $info['id'],
                    'siteId' => $siteId,
                    'description' => $description,
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
        } catch (\Throwable $e) {
            // Queue inspection failures shouldn't block rendering.
        }
        return $out;
    }

    private function hasActiveSyncJob(): bool
    {
        $jobs = $this->loadSyncJobs();
        return !empty($jobs['all']);
    }

    /**
     * Build the data needed by the Overview tab template. Decides between three
     * mutually-exclusive states (onboarding / disconnected / ready) and assembles
     * per-site cards from the vectors-side counters and entry-side coverage.
     */
    private function buildOverviewData(array $setup, array $stats): array
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
        // Detect in-flight sync jobs at render time so cards can paint
        // straight into the "Indexing" state — no JS poll round-trip.
        $activeJobs = $this->loadSyncJobs()['perSite'];

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
            } catch (\Throwable $e) {
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
                ? date('M j, Y g:i A', strtotime($row['lastIndexed']))
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
