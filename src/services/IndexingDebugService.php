<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\elements\Entry;
use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\TokenEstimator;
use yii\base\Component;

/**
 * Read-only inspector for the indexing pipeline.
 *
 * Powers the Smart Search debug view: enumerates all entries in the configured
 * indexable sections, cross-references them with stored vectors, and replays
 * per-field extraction (without writing to the DB) so we can audit exactly
 * what the indexer sees.
 */
class IndexingDebugService extends Component
{
    public const STATUS_INDEXED = 'indexed';
    public const STATUS_STALE = 'stale';
    public const STATUS_NOT_INDEXED = 'not-indexed';
    public const STATUS_EXCLUDED = 'excluded';

    public const PAGE_SIZE = 25;

    /**
     * List entries in the configured indexable sections with their indexing status.
     *
     * @param array{section?: ?string, siteId?: ?int, status?: ?string, page?: int} $filters
     * @return array{rows: array, total: int, page: int, pageSize: int}
     */
    public function getEntryRows(array $filters = []): array
    {
        $siteId = $filters['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id;
        $sectionFilter = $filters['section'] ?? null;
        $statusFilter = $filters['status'] ?? null;
        $page = max(1, (int)($filters['page'] ?? 1));

        $query = Entry::find()
            ->siteId($siteId)
            ->status(Entry::STATUS_ENABLED)
            ->uri(':notempty:')
            ->drafts(false)
            ->revisions(false);

        if ($sectionFilter !== null) {
            $query->section([$sectionFilter]);
        }

        $entries = $query->limit(null)->all();

        $summary = SmartSearch::getInstance()->databaseService->getIndexedSummary($siteId);
        $excludedKeys = SmartSearch::getInstance()->exclusionService->getExcludedKeys($siteId);

        $rows = [];
        $counts = [
            self::STATUS_INDEXED => 0,
            self::STATUS_STALE => 0,
            self::STATUS_NOT_INDEXED => 0,
            self::STATUS_EXCLUDED => 0,
            'total' => 0,
        ];
        foreach ($entries as $entry) {
            if ($entry->getUrl() === null) {
                continue;
            }
            $key = $entry->id . '-' . $entry->siteId;
            $indexed = $summary[$key] ?? null;
            $isExcluded = isset($excludedKeys[$key]);

            $status = self::STATUS_NOT_INDEXED;
            if ($isExcluded) {
                $status = self::STATUS_EXCLUDED;
            } elseif ($indexed !== null) {
                $vectorUpdated = strtotime($indexed['lastIndexed']);
                $entryUpdated = $entry->dateUpdated ? $entry->dateUpdated->getTimestamp() : 0;
                $status = $entryUpdated > $vectorUpdated ? self::STATUS_STALE : self::STATUS_INDEXED;
            }

            $counts[$status]++;
            $counts['total']++;

            if ($statusFilter !== null && $status !== $statusFilter) {
                continue;
            }

            $section = $entry->getSection();
            $rows[] = [
                'elementId' => $entry->id,
                'siteId' => $entry->siteId,
                'title' => $entry->title,
                'section' => $section?->name ?? '—',
                'sectionHandle' => $section?->handle,
                'status' => $status,
                'excluded' => $isExcluded,
                'chunkCount' => $indexed['chunkCount'] ?? 0,
                'lastIndexed' => $indexed['lastIndexed'] ?? null,
                'editUrl' => $entry->getCpEditUrl(),
            ];
        }

        $total = count($rows);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $paged = array_slice($rows, $offset, self::PAGE_SIZE);

        return [
            'rows' => $paged,
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'counts' => $counts,
        ];
    }

    /**
     * Aggregate index coverage per site for dashboard charts. Returns one row
     * per Craft site (those configured for indexing) with indexed / stale /
     * not-indexed counts. Failures during vector lookup yield zeroed entries
     * so the chart still renders.
     *
     * @return list<array{siteId: int, site: string, handle: string, indexed: int, stale: int, notIndexed: int, total: int}>
     */
    public function getCoverageBySite(): array
    {
        $out = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $entries = Entry::find()
                ->siteId($site->id)
                ->status(Entry::STATUS_ENABLED)
                ->uri(':notempty:')
                ->drafts(false)
                ->revisions(false)
                ->limit(null)
                ->all();

            try {
                $summary = SmartSearch::getInstance()->databaseService->getIndexedSummary($site->id);
            } catch (\Throwable $e) {
                Logger::exception($e, 'coverageBySite', ['siteId' => $site->id]);
                $summary = [];
            }

            $excludedKeys = SmartSearch::getInstance()->exclusionService->getExcludedKeys($site->id);

            $indexed = $stale = $notIndexed = 0;
            foreach ($entries as $entry) {
                if ($entry->getUrl() === null) {
                    continue;
                }
                $key = $entry->id . '-' . $entry->siteId;
                if (isset($excludedKeys[$key])) {
                    continue;
                }
                $row = $summary[$key] ?? null;
                if ($row === null) {
                    $notIndexed++;
                    continue;
                }
                $vectorUpdated = strtotime($row['lastIndexed']);
                $entryUpdated = $entry->dateUpdated ? $entry->dateUpdated->getTimestamp() : 0;
                if ($entryUpdated > $vectorUpdated) {
                    $stale++;
                } else {
                    $indexed++;
                }
            }

            $out[] = [
                'siteId' => (int)$site->id,
                'site' => $site->name,
                'handle' => $site->handle,
                'indexed' => $indexed,
                'stale' => $stale,
                'notIndexed' => $notIndexed,
                'total' => $indexed + $stale + $notIndexed,
            ];
        }

        return $out;
    }

    /**
     * Inspect a single entry: meta, per-field breakdown, and stored chunks.
     *
     * @return array{entry: Entry, fields: array, chunks: array, totalChunks: int}|null
     */
    public function inspectElement(int $elementId, int $siteId): ?array
    {
        $entry = Entry::find()
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->one();

        if ($entry === null) {
            return null;
        }

        $plugin = SmartSearch::getInstance();
        $fields = $plugin->embeddingService->inspectFieldsFromLayout($entry);
        $chunks = $plugin->databaseService->getVectorsForElement($elementId, $siteId);

        foreach ($chunks as &$chunk) {
            $chunk['estimatedTokens'] = $chunk['content']
                ? TokenEstimator::estimateTokens($chunk['content'])
                : 0;
        }
        unset($chunk);

        return [
            'entry' => $entry,
            'fields' => $fields,
            'chunks' => $chunks,
        ];
    }
}
