<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\i18n\Translation;
use craft\queue\Queue;
use DateTime;
use ghoststreet\craftsmartsearch\engines\BaseEngine;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\FieldPrefix;
use ghoststreet\craftsmartsearch\helpers\TokenEstimator;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\base\Component;

/**
 * Read-only inspector for the indexing pipeline.
 *
 * Powers the Index inspection views: enumerates all entries in the configured
 * indexable sections, cross-references them with stored vectors, and replays
 * per-field extraction (without writing to the DB) so we can audit exactly
 * what the indexer sees.
 */
class IndexInspectionService extends Component
{
    public const STATUS_INDEXED = 'indexed';
    public const STATUS_STALE = 'stale';
    public const STATUS_NOT_INDEXED = 'not-indexed';
    public const STATUS_EXCLUDED = 'excluded';

    public const PAGE_SIZE = 25;

    private const COVERAGE_CACHE_KEY = 'smart_search_dash_coverage';
    private const COVERAGE_CACHE_TTL = 60;

    /**
     * One page of a site's indexable entries with their indexing status, in the envelope
     * HistoryService::paginated() builds, plus the per-status counts across every page.
     *
     * @return array{items: array, total: int, page: int, perPage: int, pages: int, counts: array<string, int>}
     */
    public function getEntryRows(int $siteId, ?string $section, ?string $status, int $page): array
    {
        $query = BaseEngine::indexableEntries()->siteId($siteId);
        if ($section !== null) {
            $query->section($section);
        }

        $summary = SmartSearch::getInstance()->engine()->indexedSummary($siteId);

        $rows = [];
        $counts = [
            self::STATUS_INDEXED => 0,
            self::STATUS_STALE => 0,
            self::STATUS_NOT_INDEXED => 0,
            self::STATUS_EXCLUDED => 0,
            'total' => 0,
        ];
        foreach ($this->classify($query->all(), $siteId, $summary) as [$entry, $indexed, $entryStatus]) {
            $counts[$entryStatus]++;
            $counts['total']++;

            if ($status !== null && $entryStatus !== $status) {
                continue;
            }

            $rows[] = [
                'elementId' => $entry->id,
                'siteId' => $entry->siteId,
                'title' => $entry->title,
                'section' => $entry->getSection()?->name ?? '-',
                'status' => $entryStatus,
                'excluded' => $entryStatus === self::STATUS_EXCLUDED,
                'chunkCount' => $indexed['chunkCount'] ?? 0,
                'lastIndexed' => $indexed['lastIndexed'] ?? null,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => ($b['lastIndexed'] ?? '') <=> ($a['lastIndexed'] ?? ''));

        $paged = array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return HistoryService::paginated($paged, count($rows), $page, self::PAGE_SIZE) + ['counts' => $counts];
    }

    /**
     * Pair each entry with its summary row and status.
     *
     * @param Entry[] $entries
     * @param array<string, array{chunkCount: int, lastIndexed: string}> $summary
     * @return iterable<array{0: Entry, 1: ?array, 2: string}>
     */
    private function classify(array $entries, int $siteId, array $summary): iterable
    {
        $excludedKeys = SmartSearch::getInstance()->exclusionService->getExcludedKeys($siteId);

        foreach ($entries as $entry) {
            $key = $entry->id . '-' . $entry->siteId;
            $indexed = $summary[$key] ?? null;

            yield [$entry, $indexed, $this->statusFor($entry, $indexed, isset($excludedKeys[$key]))];
        }
    }

    /**
     * Classify one entry against its stored-vector summary row. Excluded wins over
     * everything; a missing row is not-indexed; otherwise stale iff the entry was
     * edited after its vectors were written.
     *
     * @param array{lastIndexed: string}|null $summaryRow
     */
    private function statusFor(Entry $entry, ?array $summaryRow, bool $isExcluded): string
    {
        if ($isExcluded) {
            return self::STATUS_EXCLUDED;
        }
        if ($summaryRow === null) {
            return self::STATUS_NOT_INDEXED;
        }
        $indexedAt = DateTimeHelper::toDateTime($summaryRow['lastIndexed']);
        if ($indexedAt === false) {
            return self::STATUS_STALE;
        }

        $entryUpdated = $entry->dateUpdated ? $entry->dateUpdated->getTimestamp() : 0;
        return $entryUpdated > $indexedAt->getTimestamp()
            ? self::STATUS_STALE
            : self::STATUS_INDEXED;
    }

    /**
     * Aggregate index coverage per site for dashboard charts. Returns one row
     * per Craft site with indexed / stale / not-indexed counts.
     *
     * @return list<array{siteId: int, site: string, indexed: int, stale: int, notIndexed: int, total: int}>
     */
    public function getCoverageBySite(): array
    {
        $out = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteId = (int)$site->id;
            $summary = SmartSearch::getInstance()->engine()->indexedSummary($siteId);

            $tally = [self::STATUS_INDEXED => 0, self::STATUS_STALE => 0, self::STATUS_NOT_INDEXED => 0, self::STATUS_EXCLUDED => 0];
            foreach ($this->classify(BaseEngine::indexableEntries()->siteId($siteId)->all(), $siteId, $summary) as [, , $status]) {
                $tally[$status]++;
            }

            $out[] = [
                'siteId' => $siteId,
                'site' => BaseEngine::siteName($siteId),
                'indexed' => $tally[self::STATUS_INDEXED],
                'stale' => $tally[self::STATUS_STALE],
                'notIndexed' => $tally[self::STATUS_NOT_INDEXED],
                'total' => $tally[self::STATUS_INDEXED] + $tally[self::STATUS_STALE] + $tally[self::STATUS_NOT_INDEXED],
            ];
        }

        return $out;
    }

    /**
     * getCoverageBySite() is heavy, so the Dashboard and Preview share one short-lived copy.
     *
     * @return list<array{siteId: int, site: string, indexed: int, stale: int, notIndexed: int, total: int}>
     */
    public function getCachedCoverageBySite(): array
    {
        return Craft::$app->getCache()->getOrSet(
            self::COVERAGE_CACHE_KEY,
            fn() => $this->getCoverageBySite(),
            self::COVERAGE_CACHE_TTL,
            CacheTag::dependency(),
        );
    }

    public function invalidateCoverage(): void
    {
        Craft::$app->getCache()->delete(self::COVERAGE_CACHE_KEY);
    }

    /**
     * In-flight sync jobs for the active engine, keyed by siteId, with the progress Craft
     * stored on each queue row. Matched on the serialized job class rather than on the
     * (translated) description, so it is locale-proof.
     *
     * Read from the queue's own table, which a proxy queue (Redis, SQS) keeps filling too:
     * Craft records every job there and hands the proxy only a pointer to it.
     *
     * @return array<int, array{id: string, siteId: int, progress: int, progressLabel: ?string, status: int, error: ?string}>
     */
    public function syncJobs(): array
    {
        $class = SmartSearch::getInstance()->engine()->syncJobClass();
        /** @var Queue $queue */
        $queue = Craft::$app->getQueue();

        $rows = (new Query())
            ->select(['id', 'job', 'progress', 'progressLabel', 'timeUpdated', 'fail', 'error'])
            ->from($queue->tableName)
            ->where(['like', 'job', $class])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $out = [];
        foreach ($rows as $row) {
            $job = $queue->serializer->unserialize(is_resource($row['job']) ? stream_get_contents($row['job']) : (string)$row['job']);
            if (!is_a($job, $class)) {
                continue;
            }

            /** @phpstan-ignore property.notFound (the engine's sync job declares $siteId; is_a() against the registry's class-string is the runtime guarantee) */
            $siteId = (int)$job->siteId;
            $out[$siteId] = [
                'id' => (string)$row['id'],
                'siteId' => $siteId,
                'progress' => (int)$row['progress'],
                'progressLabel' => Translation::translate((string)$row['progressLabel']) ?: null,
                'status' => $row['fail'] ? Queue::STATUS_FAILED : ($row['timeUpdated'] ? Queue::STATUS_RESERVED : Queue::STATUS_WAITING),
                'error' => $row['error'],
            ];
        }

        return $out;
    }

    /**
     * Inspect a single entry: meta, per-field breakdown, and stored chunks.
     *
     * @return array{entry: Entry, fields: array, chunks: array, boosts: array, status: string, lastIndexed: DateTime|false|null}|null
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
        $chunks = $plugin->engine()->chunksForElement($elementId, $siteId);
        $summaryRow = $chunks ? ['lastIndexed' => max(array_column($chunks, 'dateUpdated'))] : null;
        $isExcluded = isset($plugin->exclusionService->getExcludedKeys($siteId)[$elementId . '-' . $siteId]);

        return [
            'entry' => $entry,
            'status' => $this->statusFor($entry, $summaryRow, $isExcluded),
            'lastIndexed' => $summaryRow ? DateTimeHelper::toDateTime($summaryRow['lastIndexed']) : null,
            'fields' => $this->markStoredFields($fields, self::normalize(self::storedText($chunks))),
            'chunks' => array_map(
                static function(array $chunk): array {
                    $content = FieldPrefix::forEmbedding((string)$chunk['body']);
                    return [
                        ...$chunk,
                        'content' => $content,
                        'estimatedTokens' => TokenEstimator::estimateTokens($content),
                    ];
                },
                $chunks
            ),
            'boosts' => $plugin->engine()->boostRulesForElement($elementId, $siteId),
        ];
    }

    /**
     * Show each field's text as the stored chunks hold it, so the breakdown is what the
     * index contains, not what the next index run would write: not indexed if it is absent.
     */
    private function markStoredFields(array $fields, string $storedText): array
    {
        foreach ($fields as &$field) {
            foreach ($field['blocks'] as &$block) {
                $block['fields'] = $this->markStoredFields($block['fields'], $storedText);
            }
            unset($block);

            if (!$field['indexed']) {
                continue;
            }

            if (str_contains($storedText, self::normalize($field['extractedText']))) {
                $field['extractedText'] = FieldPrefix::forEmbedding($field['extractedText']);
            } else {
                $field['indexed'] = false;
                $field['reason'] = 'not in index';
                $field['extractedText'] = '';
            }
        }
        unset($field);

        return $fields;
    }

    /**
     * The entry text as stored: chunks in order, each one's leading overlap (the tail
     * of the previous chunk, see EmbeddingService::chunkText()) dropped.
     */
    private static function storedText(array $chunks): string
    {
        $parts = [];
        $previous = '';
        foreach ($chunks as $chunk) {
            $body = (string)$chunk['body'];
            $parts[] = substr($body, self::overlapLength($previous, $body));
            $previous = $body;
        }

        return implode("\n\n", $parts);
    }

    /** Length of $body's leading overlap: its longest paragraph-bounded prefix that ends $previous, plus the separator. */
    private static function overlapLength(string $previous, string $body): int
    {
        $length = 0;
        $offset = 0;
        while ($previous !== '' && ($pos = strpos($body, "\n\n", $offset)) !== false) {
            if (str_ends_with($previous, substr($body, 0, $pos))) {
                $length = $pos + 2;
            }
            $offset = $pos + 2;
        }

        return $length;
    }

    private static function normalize(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}
