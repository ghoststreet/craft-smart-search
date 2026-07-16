<?php

namespace ghoststreet\craftsmartsearch\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\i18n\Translation;
use craft\queue\BaseBatchedJob;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Walks every enabled entry that has a URI and re-indexes those whose extracted
 * text hash has changed since the last sync. Unchanged entries are skipped
 * inside EmbeddingService::indexElement, so the per-item cost is one DB read
 * for the stored hash.
 *
 * Uses Craft's BaseBatchedJob so the CP queue UI shows native progress and an
 * "X of Y" label, and continuation jobs are spawned automatically to respect
 * TTR and memory limits.
 */
class SyncSearchIndexJob extends BaseBatchedJob
{
    public ?int $siteId = null;

    protected function loadData(): Batchable
    {
        return new QueryBatcher(
            Entry::find()
                ->siteId($this->siteId ?? '*')
                ->unique(false)
                ->status(Entry::STATUS_ENABLED)
                ->uri(':notempty:')
                ->select(['elements.id', 'elements_sites.siteId'])
                ->orderBy(['elements.id' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC])
                ->asArray()
        );
    }

    protected function processItem(mixed $item): void
    {
        $entryId = (int)$item['id'];
        $siteId = (int)$item['siteId'];

        $entry = Entry::find()
            ->id($entryId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        if (!$entry) {
            Logger::debug('Sync skipped: entry vanished mid-run', ['entryId' => $entryId, 'siteId' => $siteId]);
            return;
        }

        if ($entry->getStatus() === Entry::STATUS_DISABLED) {
            SmartSearch::getInstance()->embeddingService->deleteVector($entryId, $siteId);
            return;
        }

        SmartSearch::getInstance()->embeddingService->indexElement($entry);
    }

    protected function defaultDescription(): ?string
    {
        if ($this->siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($this->siteId);
            $label = $site?->name ?: $site?->handle ?: ('site ' . $this->siteId);
            return Translation::prep('smart-search', 'Syncing Smart Search index: {name}', [
                'name' => $label,
            ]);
        }
        return Translation::prep('smart-search', 'Syncing Smart Search index');
    }
}
