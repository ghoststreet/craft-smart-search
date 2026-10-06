<?php

namespace ghoststreet\craftsmartsearch\engines;

use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Walks every indexable entry and hands each one to the engine. Unchanged
 * entries short-circuit inside the engine's index service, so the per-item cost is one
 * read of the stored hash.
 *
 * Uses Craft's BaseBatchedJob so the CP queue UI shows native progress and an "X of Y"
 * label, and continuation jobs are spawned automatically to respect TTR and memory limits.
 */
abstract class BaseSyncIndexJob extends BaseBatchedJob
{
    public ?int $siteId = null;
    public ?string $section = null;

    protected function after(): void
    {
        SmartSearch::getInstance()->indexInspectionService->invalidateCoverage();
    }

    abstract protected function indexEntry(int $entryId, int $siteId): void;

    protected function loadData(): Batchable
    {
        $query = BaseEngine::indexableEntries($this->section)
            ->siteId($this->siteId ?? '*')
            ->unique(false)
            ->select(['elements.id', 'elements_sites.siteId'])
            ->orderBy(['elements.id' => SORT_ASC, 'elements_sites.siteId' => SORT_ASC])
            ->asArray();

        return new QueryBatcher($query);
    }

    protected function processItem(mixed $item): void
    {
        $this->indexEntry((int)$item['id'], (int)$item['siteId']);
    }
}
