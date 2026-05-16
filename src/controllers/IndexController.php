<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\elements\Entry;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\DatabaseException;
use ghoststreet\craftaisearch\helpers\ErrorMapper;
use ghoststreet\craftaisearch\helpers\Logger;
use ghoststreet\craftaisearch\jobs\IndexEntryJob;
use ghoststreet\craftaisearch\jobs\SyncSearchIndexJob;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Index management page: tabs for overview/sync, entries (debug), and coverage.
 * Replaces the previous Data Sync + Debug pages.
 */
class IndexController extends BaseApiController
{
    protected array|int|bool $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $plugin = AiSearch::getInstance();
        $settings = $plugin->getSettings();

        if (empty($settings->getOpenaiApiKey())
            || empty($settings->getPostgresqlHost())
            || empty($settings->getPostgresqlDatabase())) {
            return $this->redirect('ai-search');
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
            'syncStarted' => Craft::$app->getSession()->getFlash('ai-search-sync-started', false),
            'selectedSubnavItem' => 'index',
        ];

        if ($tab === 'entries') {
            $filters = [
                'section' => $request->getQueryParam('section') ?: null,
                'siteId' => (int)($request->getQueryParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id),
                'status' => $request->getQueryParam('status') ?: null,
                'page' => (int)($request->getQueryParam('page') ?: 1),
            ];

            try {
                $result = $plugin->indexingDebugService->getEntryRows($filters);
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
            $data['coverage'] = $plugin->indexingDebugService->getCoverageBySite();
        }

        return $this->renderTemplate('ai-search/index-mgmt/index', $data);
    }

    public function actionEntry(): Response
    {
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredQueryParam('elementId');
        $siteId = (int)$request->getRequiredQueryParam('siteId');

        $plugin = AiSearch::getInstance();

        try {
            $inspection = $plugin->indexingDebugService->inspectElement($elementId, $siteId);
            $error = null;
        } catch (DatabaseException $e) {
            $inspection = null;
            $error = ErrorMapper::present($e, 'inspectElement', ['elementId' => $elementId, 'siteId' => $siteId]);
        }

        if ($inspection === null && $error === null) {
            throw new NotFoundHttpException('Entry not found');
        }

        return $this->renderTemplate('ai-search/index-mgmt/entry', [
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

        try {
            Logger::info('Starting incremental sync');

            $entries = Entry::find()
                ->siteId('*')
                ->unique(false)
                ->status(Entry::STATUS_ENABLED)
                ->uri(':notempty:')
                ->select(['elements.id', 'elements_sites.siteId'])
                ->asArray()
                ->all();

            $activeKeys = [];
            foreach ($entries as $entry) {
                $activeKeys[] = [
                    'elementId' => (int)$entry['id'],
                    'siteId' => (int)$entry['siteId'],
                ];
            }

            $orphans = AiSearch::getInstance()->databaseService->deleteOrphanedVectors($activeKeys);

            Craft::$app->getQueue()->push(new SyncSearchIndexJob());

            $count = count($activeKeys);
            Logger::info('Queued sync job', ['entries' => $count, 'orphansRemoved' => $orphans]);

            Craft::$app->getSession()->setFlash('ai-search-sync-started', true);
            Craft::$app->getSession()->setNotice(
                Craft::t('ai-search', 'Sync queued for {count} entries. {orphans} orphaned vectors removed.', [
                    'count' => $count,
                    'orphans' => $orphans,
                ])
            );
        } catch (DatabaseException $e) {
            Craft::$app->getSession()->setError(
                Craft::t('ai-search', 'Failed to start sync: {error}', ['error' => ErrorMapper::present($e, 'sync')])
            );
        }

        return $this->redirect('ai-search/index');
    }

    public function actionGetStats(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        try {
            $stats = AiSearch::getInstance()->databaseService->getStats(false);
            $queue = Craft::$app->getQueue();
            $queueTotal = $queue->getTotalWaiting();

            $sync = null;
            foreach ($queue->getJobInfo(50) as $info) {
                if (str_contains((string)$info['description'], 'Syncing AI search index')) {
                    $sync = [
                        'id' => $info['id'],
                        'description' => $info['description'],
                        'progress' => $info['progress'],
                        'progressLabel' => $info['progressLabel'],
                        'status' => $info['status'],
                        'error' => $info['error'] ?? null,
                    ];
                    break;
                }
            }

            return $this->asJson([
                'success' => true,
                'entryCount' => $stats['entryCount'],
                'chunkCount' => $stats['chunkCount'],
                'queueRemaining' => $queueTotal,
                'sync' => $sync,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonError($e, 'getStats');
        }
    }

    public function actionCancelSync(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $queue = Craft::$app->getQueue();
        $released = 0;

        foreach ($queue->getJobInfo(100) as $info) {
            if (!str_contains((string)$info['description'], 'Syncing AI search index')) {
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

    public function actionLegacyRedirect(): Response
    {
        return $this->redirect('ai-search/index');
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

        Craft::$app->getSession()->setNotice(Craft::t('ai-search', 'Re-index queued for entry #{id}.', ['id' => $elementId]));

        return $this->redirectToPostedUrl();
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
