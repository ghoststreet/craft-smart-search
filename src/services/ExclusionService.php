<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\records\ExcludedEntryRecord;
use yii\base\Component;

/**
 * Tracks entries that have been manually excluded from the search index.
 *
 * An exclusion is a row in smart_search_excluded_entries. Every indexing path runs
 * through EmbeddingService::indexElement(), which checks isExcluded() and skips
 * (and prunes vectors for) excluded entries, so bulk sync and entry-save events
 * all respect exclusions automatically.
 */
class ExclusionService extends Component
{
    public function isExcluded(int $elementId, int $siteId): bool
    {
        return ExcludedEntryRecord::find()
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->exists();
    }

    /**
     * Exclude an entry: record the exclusion and remove its vectors immediately.
     */
    public function exclude(int $elementId, int $siteId): void
    {
        $record = ExcludedEntryRecord::findOne(['elementId' => $elementId, 'siteId' => $siteId]);
        if ($record === null) {
            $record = new ExcludedEntryRecord();
            $record->elementId = $elementId;
            $record->siteId = $siteId;
            $record->save();
        }

        SmartSearch::getInstance()->embeddingService->deleteVector($elementId, $siteId);
        Logger::info('Excluded entry from index', ['elementId' => $elementId, 'siteId' => $siteId]);
    }

    /**
     * Remove an entry's exclusion so it can be indexed again.
     */
    public function include(int $elementId, int $siteId): void
    {
        ExcludedEntryRecord::deleteAll(['elementId' => $elementId, 'siteId' => $siteId]);
        Logger::info('Re-included entry in index', ['elementId' => $elementId, 'siteId' => $siteId]);
    }

    /**
     * Return excluded entries as a set of "elementId-siteId" keys for cheap lookups.
     *
     * @return array<string, true>
     */
    public function getExcludedKeys(?int $siteId = null): array
    {
        $query = ExcludedEntryRecord::find()->select(['elementId', 'siteId'])->asArray();
        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }

        $keys = [];
        foreach ($query->all() as $row) {
            $keys[$row['elementId'] . '-' . $row['siteId']] = true;
        }

        return $keys;
    }
}
