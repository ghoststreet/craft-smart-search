<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\DatabaseException;
use ghoststreet\craftaisearch\helpers\Logger;
use ghoststreet\craftaisearch\jobs\CleanupStaleVectorsJob;
use ghoststreet\craftaisearch\jobs\IndexEntryJob;
use yii\web\Response;

/**
 * Data Sync controller for content indexing management
 */
class DataSyncController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Render the data sync page with current indexing statistics.
     */
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        try {
            $stats = AiSearch::getInstance()->databaseService->getStats();
        } catch (DatabaseException $e) {
            $stats = [
                'entryCount' => 0,
                'chunkCount' => 0,
                'lastIndexed' => null,
                'isConnected' => false,
                'error' => $e->getMessage(),
            ];
        }

        return $this->renderTemplate('ai-search/data-sync', [
            'plugin' => AiSearch::getInstance(),
            'stats' => $stats,
            'syncStarted' => Craft::$app->getSession()->getFlash('ai-search-sync-started', false),
        ]);
    }

    /**
     * Incrementally sync the vector database: queue all valid entries for
     * re-indexing, then queue a cleanup job to remove stale vectors.
     *
     * Existing search data remains available throughout the process.
     *
     * @throws DatabaseException If the operation fails (caught internally for UI feedback)
     */
    public function actionWipeAndReindex(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        try {
            Logger::info('Starting incremental sync operation');

            $entries = Entry::find()
                ->status(null)
                ->uri(':notempty:')
                ->select(['elements.id', 'elements_sites.siteId'])
                ->asArray()
                ->all();

            $queue = Craft::$app->getQueue();
            $validPairs = [];

            foreach ($entries as $entry) {
                $entryId = (int)$entry['id'];
                $siteId = (int)$entry['siteId'];

                $queue->push(new IndexEntryJob([
                    'entryId' => $entryId,
                    'siteId' => $siteId,
                ]));

                $validPairs[] = ['elementId' => $entryId, 'siteId' => $siteId];
            }

            $queue->push(new CleanupStaleVectorsJob([
                'validPairs' => $validPairs,
            ]));

            $count = count($entries);
            Logger::info('Queued entries for sync', ['count' => $count]);

            Craft::$app->getSession()->setFlash('ai-search-sync-started', true);
            Craft::$app->getSession()->setNotice(
                Craft::t('ai-search', '{count} entries queued for syncing. Stale vectors will be cleaned up after indexing completes. Check Utilities → Queue for progress.', [
                    'count' => $count,
                ])
            );
        } catch (DatabaseException $e) {
            Logger::exception($e, 'syncReindex');
            Craft::$app->getSession()->setError(
                Craft::t('ai-search', 'Failed to start sync: {error}', [
                    'error' => $e->getMessage(),
                ])
            );
        }

        return $this->redirect('ai-search/data-sync');
    }

    /**
     * Return current indexing statistics as JSON for AJAX polling.
     */
    public function actionGetStats(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        try {
            $stats = AiSearch::getInstance()->databaseService->getStats(false);
            $queueTotal = Craft::$app->getQueue()->getTotalWaiting();

            return $this->asJson([
                'success' => true,
                'entryCount' => $stats['entryCount'],
                'chunkCount' => $stats['chunkCount'],
                'queueRemaining' => $queueTotal,
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
