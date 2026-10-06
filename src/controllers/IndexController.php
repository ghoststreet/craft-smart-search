<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\queue\Queue;
use ghoststreet\craftsmartsearch\engines\BaseEngine;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\i18n\Formatter;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Index management page: per-site coverage totals with a sync trigger, and the
 * entry table.
 */
class IndexController extends BaseCpController
{
    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $filters = [
            'section' => $request->getQueryParam('section') ?: null,
            'siteId' => RequestParameterExtractor::resolveSiteId($request->getQueryParam('siteId'))[0] ?? $currentSiteId,
            'status' => $request->getQueryParam('status') ?: null,
            'page' => max(1, (int)$request->getQueryParam('page', 1)),
        ];

        $inspection = SmartSearch::getInstance()->indexInspectionService;

        return $this->renderTemplate('smart-search/index-mgmt/entries', [
            'filters' => $filters,
            'hasActiveFilters' => $filters['section'] !== null || $filters['status'] !== null || $filters['siteId'] !== $currentSiteId,
            'sections' => array_map(
                static fn(int $id) => Craft::$app->getEntries()->getSectionById($id),
                SmartSearch::getInstance()->getSettings()->indexedSectionIds(),
            ),
            'sites' => Craft::$app->getSites()->getAllSites(),
            'result' => $inspection->getEntryRows($filters['siteId'], $filters['section'], $filters['status'], $filters['page']),
            'activeJob' => $inspection->syncJobs()[$filters['siteId']] ?? null,
        ]);
    }

    public function actionEntry(): Response
    {
        [$elementId, $siteId] = $this->requireElementSite();

        $inspection = SmartSearch::getInstance()->indexInspectionService->inspectElement($elementId, $siteId);

        if ($inspection === null) {
            throw new NotFoundHttpException('Entry not found');
        }

        return $this->renderTemplate('smart-search/index-mgmt/entry', [
            'inspection' => $inspection,
        ]);
    }

    /**
     * Queue a bulk reindex. Unchanged entries are skipped by the fingerprint check,
     * so this is incremental unless the index was cleared first.
     */
    public function actionSync(): Response
    {
        $this->requirePostRequest();

        $siteId = $this->optionalSiteId();
        SmartSearch::getInstance()->engine()->queueSync($siteId);

        $count = $siteId !== null ? 1 : count(Craft::$app->getSites()->getAllSiteIds());
        Logger::info('Queued sync job', ['sites' => $count, 'siteId' => $siteId]);

        return $this->respond([], Craft::t('smart-search', 'Reindex queued for {count} site(s). Unchanged entries are skipped.', [
            'count' => $count,
        ]));
    }

    public function actionGetStats(): Response
    {
        $this->requireAcceptsJson();

        /** @var Queue $queue */
        $queue = Craft::$app->getQueue();

        return $this->asJson([
            'success' => true,
            'jobs' => array_values(SmartSearch::getInstance()->indexInspectionService->syncJobs()),
            'queueRemaining' => $queue->getTotalWaiting(),
        ]);
    }

    public function actionCancelSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = $this->optionalSiteId();

        /** @var Queue $queue */
        $queue = Craft::$app->getQueue();
        $released = 0;

        foreach (SmartSearch::getInstance()->indexInspectionService->syncJobs() as $job) {
            if ($siteId !== null && $job['siteId'] !== $siteId) {
                continue;
            }
            $queue->release($job['id']);
            $released++;
        }

        Logger::info('Cancelled sync', ['released' => $released, 'siteId' => $siteId]);

        return $this->asJson(['success' => true]);
    }

    public function actionReindexEntry(): Response
    {
        $this->requirePostRequest();
        [$elementId, $siteId] = $this->requireElementSite();

        return $this->queueIndexJob(
            $elementId,
            $siteId,
            Craft::t('smart-search', 'Re-index queued for entry #{id}.', ['id' => $elementId])
        );
    }

    public function actionExcludeEntry(): Response
    {
        $this->requirePostRequest();
        [$elementId, $siteId] = $this->requireElementSite();

        SmartSearch::getInstance()->exclusionService->exclude($elementId, $siteId);

        return $this->respond([], Craft::t('smart-search', 'Entry #{id} excluded from index.', ['id' => $elementId]));
    }

    public function actionIncludeEntry(): Response
    {
        $this->requirePostRequest();
        [$elementId, $siteId] = $this->requireElementSite();

        SmartSearch::getInstance()->exclusionService->include($elementId, $siteId);

        return $this->queueIndexJob(
            $elementId,
            $siteId,
            Craft::t('smart-search', 'Entry #{id} re-included in index.', ['id' => $elementId])
        );
    }

    /** Push an index job, then answer JSON (with jobId) or redirect with a notice. */
    private function queueIndexJob(int $elementId, int $siteId, string $notice): Response
    {
        $entry = BaseEngine::findEntry($elementId, $siteId);
        $jobId = $entry !== null ? SmartSearch::getInstance()->engine()->queueIndex($entry) : null;

        return $this->respond(['jobId' => (string)$jobId], $notice);
    }

    /** JSON success for XHR callers, otherwise a CP notice and a redirect back to the page. */
    private function respond(array $json, string $notice): Response
    {
        $request = Craft::$app->getRequest();

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true] + $json);
        }

        Craft::$app->getSession()->setNotice($notice);

        return $this->redirect($request->getReferrer() ?? 'smart-search/index');
    }

    /**
     * The elementId/siteId pair every per-entry action takes, from the body on POST
     * and the query string otherwise.
     *
     * @return array{0: int, 1: int}
     */
    private function requireElementSite(): array
    {
        $request = Craft::$app->getRequest();
        $read = $request->getIsPost()
            ? fn(string $name): mixed => $request->getRequiredBodyParam($name)
            : fn(string $name): mixed => $request->getRequiredQueryParam($name);

        return [(int)$read('elementId'), (int)$read('siteId')];
    }

    /** A posted siteId, or null for "every site". */
    private function optionalSiteId(): ?int
    {
        $raw = Craft::$app->getRequest()->getBodyParam('siteId');

        return ($raw !== null && $raw !== '') ? (int)$raw : null;
    }

    public function actionEntryState(): Response
    {
        $this->requireAcceptsJson();
        [$elementId, $siteId] = $this->requireElementSite();

        $row = SmartSearch::getInstance()->engine()->indexedSummary($siteId)[$elementId . '-' . $siteId] ?? null;

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
        $this->requireAcceptsJson();

        $jobId = (string)Craft::$app->getRequest()->getRequiredQueryParam('id');

        return $this->asJson([
            'success' => true,
            'done' => Craft::$app->getQueue()->status($jobId) >= Queue::STATUS_DONE,
        ]);
    }
}
