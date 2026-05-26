<?php

namespace ghoststreet\craftsmartsearch\jobs;

use craft\elements\Entry;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use ghoststreet\craftsmartsearch\exceptions\SearchException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Queue job to index a single entry for Smart Search.
 *
 * Fetches the entry by ID and site, extracts text, generates embeddings,
 * and stores vectors. Throws if the entry no longer exists so the job
 * queue marks the job as failed rather than silently succeeding.
 */
class IndexEntryJob extends BaseJob
{
    public int $entryId;
    public int $siteId;

    /**
     * @throws SearchException If the entry no longer exists
     */
    public function execute($queue): void
    {
        $entry = Entry::find()
            ->id($this->entryId)
            ->siteId($this->siteId)
            ->status(null)
            ->one();

        if (!$entry) {
            throw SearchException::indexEntryNotFound($this->entryId, $this->siteId);
        }

        // Status may have flipped between enqueue and execution.
        if ($entry->getStatus() === Entry::STATUS_DISABLED) {
            SmartSearch::getInstance()->embeddingService->deleteVector($this->entryId, $this->siteId);
            Logger::info('Skipped indexing disabled entry; removed any existing vectors', [
                'entryId' => $this->entryId,
                'siteId' => $this->siteId,
                'status' => $entry->getStatus(),
            ]);
            return;
        }

        SmartSearch::getInstance()->embeddingService->indexElement($entry);
        Logger::info('Indexed entry via job', ['entryId' => $this->entryId]);

        SmartSearch::getInstance()->dictionaryService->requestRebuild();
    }

    protected function defaultDescription(): ?string
    {
        $entry = Entry::find()
            ->id($this->entryId)
            ->siteId($this->siteId)
            ->status(null)
            ->one();

        $title = $entry?->title ?: "#{$this->entryId}";

        return Translation::prep('smart-search', 'Smart Search: indexing “{title}”', [
            'title' => $title,
        ]);
    }
}
