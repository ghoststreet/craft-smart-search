<?php

namespace ghoststreet\craftsmartsearch\engines;

use craft\elements\Entry;
use yii\db\Connection;

/**
 * One complete search engine: its own schema, its own index pipeline, its own ranking.
 *
 * Engines share no tables, no SQL and no scoring code, so a change to one cannot alter
 * another's results. What they do share sits outside this interface and is not engine
 * business: EmbeddingService turns entries into chunks and chunks into vectors,
 * Ranker fuses two signal lists, HistoryService records what was searched.
 *
 * Adding an engine is a new directory under engines/ plus one line in EngineRegistry.
 * That is the whole extension story, and it is why MySQL 9's VECTOR type, when it
 * arrives, is a new engine rather than a mode of an existing one.
 *
 * Engines are selected by host capability, never by a setting. supports() answers "can
 * this database run me at all"; isInstalled() answers "have my tables been created here".
 */
interface SearchEngine
{
    /** Can this host run this engine at all? Driver and extension probe, no DDL. */
    public static function supports(Connection $db): bool;

    /** Stable identifier, shown by the console `engines` command. */
    public function handle(): string;

    /** Human name for the control panel. */
    public function label(): string;

    public function isInstalled(): bool;

    /** True once anything has been indexed. */
    public function hasIndex(): bool;

    /**
     * @param list<int>|null $sectionIds null means every section, [] means none
     * @return array<array<string, mixed>>
     */
    public function search(string $query, int $limit, ?int $siteId = null, ?array $sectionIds = null): array;

    /** @return string|null the queue job id, when the engine queued one */
    public function queueIndex(Entry $entry): ?string;

    /**
     * One job removing these entries from the index on one site.
     *
     * @param list<int> $elementIds
     */
    public function queueDelete(array $elementIds, int $siteId): void;

    /** One batched sync job per site (or just $siteId), optionally limited to one section handle. */
    public function queueSync(?int $siteId, ?string $section = null): void;

    /** The batched sync job class, so the CP can read progress out of the queue. */
    public function syncJobClass(): string;

    /** @return array<string, mixed> entryCount, chunkCount, lastIndexed, plus engine extras */
    public function stats(?int $siteId = null): array;

    /** @return array<string, array{chunkCount: int, lastIndexed: string}> keyed "elementId-siteId" */
    public function indexedSummary(?int $siteId = null): array;

    /** @return list<array<string, mixed>> */
    public function chunksForElement(int $elementId, int $siteId): array;

    /** @return list<array{label: string, weight: float}> */
    public function boostRulesForElement(int $elementId, int $siteId): array;

    public function clearAll(?int $siteId = null): int;
}
