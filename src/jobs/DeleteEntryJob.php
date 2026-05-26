<?php

namespace ghoststreet\craftsmartsearch\jobs;

use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\records\ExcludedEntryRecord;

/**
 * Job to delete vectors for a removed entry
 */
class DeleteEntryJob extends BaseJob
{
    public int $entryId;
    public int $siteId;

    public function execute($queue): void
    {
        SmartSearch::getInstance()->embeddingService->deleteVector($this->entryId, $this->siteId);
        ExcludedEntryRecord::deleteAll(['elementId' => $this->entryId, 'siteId' => $this->siteId]);
        Logger::info('Deleted vectors via job', ['entryId' => $this->entryId, 'siteId' => $this->siteId]);

        SmartSearch::getInstance()->dictionaryService->requestRebuild();
    }

    protected function defaultDescription(): ?string
    {
        return "Deleting Smart Search vectors for entry #{$this->entryId}";
    }
}
