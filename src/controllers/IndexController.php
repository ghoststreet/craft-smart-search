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

        $data = [
            'tab' => $tab,
            'plugin' => $plugin,
            'settings' => $settings,
            'stats' => $stats,
            'setup' => $setup,
            'syncStarted' => Craft::$app->getSession()->getFlash('smart-search-sync-started', false),
            'selectedSubnavItem' => 'index',
        ];

        if ($tab === 'overview') {
            $data['overview'] = $this->buildOverviewData($setup, $stats);
        }

        if ($tab === 'entries') {
            $filters = [
                'section' => $request->getQueryParam('section') ?: null,
                'siteId' => (int)($request->getQueryParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id),
                'status' => $request->getQueryParam('status') ?: null,
                'page' => (int)($request->getQueryParam('page') ?: 1),
            ];

            try {
                $result = $plugin->indexInspectionService->getEntryRows($filters);
                $error = null;
            } catch (DatabaseException $e) {
                $result = ['rows' => [], 'total' => 0, 'page' => 1, 'pageSize' => 25, 'counts' => ['indexed' => 0, 'stale' => 0, 'not-indexed' => 0, 'total' => 0]];
                $error = ErrorMapper::present($e, 'getEntryRows', ['siteId' => $filters['siteId']]);
            }

            $data['result'] = $result;
            $data['filters'] = $filters;
            $data['sections'] = Craft::$app->getEntries()->getAllSections();
            $data['sites'] = Craft::$app->getSites()->getAllSites();
            $data['error'] = $error;
        } elseif ($tab === 'coverage') {
            $data['coverage'] = $plugin->indexInspectionService->getCoverageBySite();
        }

        return $this->renderTemplate('smart-search/index-mgmt/index', $data);
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
            Craft::$app->getSession()->setNotice(
                Craft::t('smart-search', 'Sync queued for {count} entries. {orphans} orphaned vectors removed.', [
                    'count' => $count,
                    'orphans' => $orphans,
                ])
            );
        } catch (DatabaseException $e) {
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
            $perSite = $plugin->databaseService->getStatsPerSite();
            $queue = Craft::$app->getQueue();
            $queueTotal = $queue->getTotalWaiting();

            $jobs = [];
            $globalSync = null;
            foreach ($queue->getJobInfo(100) as $info) {
                $description = (string)$info['description'];
                if (!str_contains($description, 'Syncing Smart Search index')) {
                    continue;
                }
                $siteId = null;
                if (preg_match('/\[site:(\d+)\]/', $description, $m)) {
                    $siteId = (int)$m[1];
                }
                $entry = [
                    'id' => $info['id'],
                    'siteId' => $siteId,
                    'description' => $description,
                    'progress' => $info['progress'],
                    'progressLabel' => $info['progressLabel'],
                    'status' => $info['status'],
                    'error' => $info['error'] ?? null,
                ];
                if ($siteId === null && $globalSync === null) {
                    $globalSync = $entry;
                } elseif ($siteId !== null) {
                    $jobs[] = $entry;
                }
            }

            return $this->asJson([
                'success' => true,
                'entryCount' => $stats['entryCount'],
                'chunkCount' => $stats['chunkCount'],
                'perSite' => $perSite,
                'jobs' => $jobs,
                'queueRemaining' => $queueTotal,
                'sync' => $globalSync,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError($e, 'getStats');
        }
    }

    /**
     * Build the data needed by the Overview tab template. Decides between three
     * mutually-exclusive states (onboarding / disconnected / ready) and assembles
     * per-site cards from the vectors-side counters and entry-side coverage.
     */
    private function buildOverviewData(array $setup, array $stats): array
    {
        $plugin = SmartSearch::getInstance();
        $sites = Craft::$app->getSites()->getAllSites();
        $isMultiSite = count($sites) > 1;

        if (!$setup['credentials'] || !$setup['schema'] || !$setup['openaiKey']) {
            return [
                'state' => 'onboarding',
                'error' => null,
                'sites' => [],
                'isMultiSite' => $isMultiSite,
            ];
        }

        if (!$setup['connection']) {
            return [
                'state' => 'disconnected',
                'error' => $stats['error'] ?? null,
                'sites' => [],
                'isMultiSite' => $isMultiSite,
            ];
        }

        try {
            $perSite = $plugin->databaseService->getStatsPerSite();
        } catch (DatabaseException) {
            $perSite = [];
        }
        $statsBySiteId = [];
        foreach ($perSite as $row) {
            $statsBySiteId[$row['siteId']] = $row;
        }

        $coverage = $plugin->indexInspectionService->getCoverageBySite();
        $coverageBySiteId = [];
        foreach ($coverage as $row) {
            $coverageBySiteId[$row['siteId']] = $row;
        }

        $rows = [];
        foreach ($sites as $site) {
            $sid = (int)$site->id;
            $cov = $coverageBySiteId[$sid] ?? ['indexed' => 0, 'stale' => 0, 'notIndexed' => 0, 'total' => 0];
            $st = $statsBySiteId[$sid] ?? ['entryCount' => 0, 'chunkCount' => 0, 'lastIndexed' => null];
            $rows[] = [
                'siteId' => $sid,
                'name' => $site->name ?: $site->handle,
                'handle' => $site->handle,
                'indexed' => $cov['indexed'],
                'stale' => $cov['stale'],
                'notIndexed' => $cov['notIndexed'],
                'total' => $cov['total'],
                'chunkCount' => $st['chunkCount'],
                'lastIndexed' => $st['lastIndexed'],
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

        $queue = Craft::$app->getQueue();
        $released = 0;

        foreach ($queue->getJobInfo(100) as $info) {
            if (!str_contains((string)$info['description'], 'Syncing Smart Search index')) {
                continue;
            }
            try {
                $queue->release((string)$info['id']);
                $released++;
            } catch (\Throwable $e) {
                Logger::exception($e, 'cancelSync', ['jobId' => $info['id']]);
            }
        }

        Logger::info('Cancelled sync', ['released' => $released]);

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
