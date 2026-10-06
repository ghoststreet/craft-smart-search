<?php

namespace ghoststreet\craftsmartsearch\engines\local\jobs;

use Craft;
use craft\i18n\Translation;
use ghoststreet\craftsmartsearch\engines\BaseEngine;
use ghoststreet\craftsmartsearch\engines\BaseSyncIndexJob;
use ghoststreet\craftsmartsearch\engines\local\LocalIndexService;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * A failed entry fails the job, so it shows as failed in the queue. Retrying is cheap:
 * entries already indexed are skipped by their content hash.
 */
class LocalSyncIndexJob extends BaseSyncIndexJob
{
    protected function indexEntry(int $entryId, int $siteId): void
    {
        LocalIndexService::instance()->syncOrPrune($entryId, $siteId);
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
