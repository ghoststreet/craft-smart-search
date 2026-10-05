<?php

namespace ghoststreet\craftsmartsearch\engines\local\jobs;

use Craft;
use craft\i18n\Translation;
use ghoststreet\craftsmartsearch\engines\BaseEngine;
use ghoststreet\craftsmartsearch\engines\BaseSyncIndexJob;
use ghoststreet\craftsmartsearch\engines\local\LocalIndexService;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;

class LocalSyncIndexJob extends BaseSyncIndexJob
{
    /**
     * High enough that neighbouring bad entries cannot trip it, low enough that a cause
     * affecting every entry fails immediately rather than grinding through the site.
     */
    private const FAILURE_ABORT_THRESHOLD = 5;

    private int $consecutiveFailures = 0;

    protected function indexEntry(int $entryId, int $siteId): void
    {
        try {
            LocalIndexService::instance()->syncOrPrune($entryId, $siteId);
            $this->consecutiveFailures = 0;
        } catch (Throwable $e) {
            $this->consecutiveFailures++;

            if ($this->consecutiveFailures >= self::FAILURE_ABORT_THRESHOLD) {
                Logger::error('Sync aborted: {count} entries failed in a row, so the cause is not the entry', [
                    'count' => $this->consecutiveFailures,
                    'entryId' => $entryId,
                    'siteId' => $siteId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            Logger::error('Sync skipped one entry and continued', [
                'entryId' => $entryId,
                'siteId' => $siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The related-term model is corpus-wide, so it can only be built once indexing is done,
     * and only matters when it is the vector source: with a provider key there is nothing to learn.
     */
    protected function after(): void
    {
        parent::after();

        if (SmartSearch::getInstance()->vectorSources->isLocal()) {
            Craft::$app->getQueue()->push(new LocalBuildVectorsJob(['siteId' => $this->siteId]));
        }
    }

    protected function defaultDescription(): ?string
    {
        if ($this->siteId !== null) {
            return Translation::prep('smart-search', 'Syncing the local Smart Search index: {name}', [
                'name' => BaseEngine::siteName($this->siteId),
            ]);
        }
        return Translation::prep('smart-search', 'Syncing the local Smart Search index');
    }
}
