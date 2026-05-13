<?php

namespace ghoststreet\craftaisearch\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\DatabaseException;
use ghoststreet\craftaisearch\helpers\ApiResponseHelper;
use ghoststreet\craftaisearch\helpers\Logger;
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

        return $this->renderTemplate('ai-search/data-sync', [
            'plugin' => AiSearch::getInstance(),
            'stats' => AiSearch::getInstance()->databaseService->getStatsSafe(),
            'syncStarted' => Craft::$app->getSession()->getFlash('ai-search-sync-started', false),
        ]);
    }

    /**
     * Destructively rebuild the vector database: truncate `aisearch_vectors`,
     * then queue an IndexEntryJob for every currently-valid entry.
     *
     * Search returns empty results until the queue drains. Steady-state drift
     * between Craft and the vectors table is handled by the live element
     * save/delete event handlers in AiSearch::registerEventHandlers(); this
     * action is the recovery / initial-load path.
     *
     * @throws DatabaseException If the operation fails (caught internally for UI feedback)
     */
    public function actionWipeAndReindex(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        try {
            Logger::info('Starting wipe-and-reindex operation');

            AiSearch::getInstance()->databaseService->clearAllVectors();

            $entries = Entry::find()
                ->status(null)
                ->uri(':notempty:')
                ->select(['elements.id', 'elements_sites.siteId'])
                ->asArray()
                ->all();

            $queue = Craft::$app->getQueue();

            foreach ($entries as $entry) {
                $queue->push(new IndexEntryJob([
                    'entryId' => (int)$entry['id'],
                    'siteId' => (int)$entry['siteId'],
                ]));
            }

            $count = count($entries);
            Logger::info('Queued entries for sync', ['count' => $count]);

            Craft::$app->getSession()->setFlash('ai-search-sync-started', true);
            Craft::$app->getSession()->setNotice(
                Craft::t('ai-search', 'Search index cleared. {count} entries queued for reindexing. Search results will return as the queue processes.', [
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
            return ApiResponseHelper::jsonError($this, $e, 'getStats');
        }
    }
}
