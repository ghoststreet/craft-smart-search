<?php

namespace ghoststreet\craftsmartsearch\engines\local;

use Craft;
use craft\db\Query;
use ghoststreet\craftsmartsearch\engines\BaseEngine;
use ghoststreet\craftsmartsearch\engines\local\jobs\LocalDeleteEntryJob;
use ghoststreet\craftsmartsearch\engines\local\jobs\LocalIndexEntryJob;
use ghoststreet\craftsmartsearch\engines\local\jobs\LocalSyncIndexJob;
use yii\db\Connection;

/**
 * Search scored entirely in Craft's own database, on any driver it supports.
 *
 * Semantic similarity is an exact cosine scan in PHP over packed float32 blobs, keyword
 * scoring is BM25 over an inverted index, typo correction is levenshtein over a
 * length-banded dictionary, and boost rules are matched by phrase adjacency. Nothing
 * here needs a database extension, which is what makes it the engine every host can run
 * and therefore the last entry in the registry.
 *
 * Its tables are created by the plugin's install migration.
 */
class LocalEngine extends BaseEngine
{
    private LocalSearchService $search;

    private LocalIndexService $index;

    public function __construct()
    {
        $this->search = new LocalSearchService();
        $this->index = LocalIndexService::instance();
    }

    public static function supports(Connection $db): bool
    {
        return true;
    }

    public function handle(): string
    {
        return 'local';
    }

    public function label(): string
    {
        return 'Standard';
    }

    public function isInstalled(): bool
    {
        return Craft::$app->getDb()->tableExists(LocalSchema::CHUNKS_TABLE);
    }

    public function hasIndex(): bool
    {
        return (new Query())->from(LocalSchema::CHUNKS_TABLE)->exists();
    }

    public function search(string $query, int $limit, ?int $siteId = null, ?array $sectionIds = null): array
    {
        return $this->search->search($query, $limit, $siteId, $sectionIds);
    }

    public function syncJobClass(): string
    {
        return LocalSyncIndexJob::class;
    }

    protected function indexJobClass(): string
    {
        return LocalIndexEntryJob::class;
    }

    protected function deleteJobClass(): string
    {
        return LocalDeleteEntryJob::class;
    }

    public function stats(?int $siteId = null): array
    {
        return $this->index->stats($siteId);
    }

    public function indexedSummary(?int $siteId = null): array
    {
        return $this->index->getIndexedSummary($siteId);
    }

    public function chunksForElement(int $elementId, int $siteId): array
    {
        return $this->index->getChunksForElement($elementId, $siteId);
    }

    public function boostRulesForElement(int $elementId, int $siteId): array
    {
        return $this->index->getBoostRulesForElement($elementId, $siteId);
    }

    public function clearAll(?int $siteId = null): int
    {
        return $this->index->clearAll($siteId);
    }
}
