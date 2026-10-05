<?php

namespace ghoststreet\craftsmartsearch\engines\local;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use DateTime;
use ghoststreet\craftsmartsearch\engines\BaseEngine;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\FieldPrefix;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\Stemmer;
use ghoststreet\craftsmartsearch\helpers\TokenEstimator;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Write side of the search index: chunks, vectors, postings, dictionary and boost rules,
 * all in Craft's own database.
 *
 * Text extraction and chunking are EmbeddingService's, so what gets indexed is decided
 * in one place; this class owns only how it is stored. A content hash short-circuits an
 * unchanged entry before any embedding is requested, which is what makes a full reindex
 * cheap to re-run.
 */
class LocalIndexService
{
    private static ?self $instance = null;

    /** Engine-internal accessor: these services are not plugin components. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Vectors are packed little-endian explicitly, so a dump moves between machines. */
    private const PACK_FORMAT = 'g*';

    private const CACHE_TOKEN_KEY = 'smart_search_local_token';

    /** IN-list chunking for the dictionary refresh. */
    private const TERM_BATCH = 500;

    /** Width of the postings and dictionary term columns. */
    private const TERM_MAX_LENGTH = 100;

    /** Index one entry on one site, or prune it when it is disabled or gone. */
    public function syncOrPrune(int $entryId, int $siteId): void
    {
        $entry = BaseEngine::findEntry($entryId, $siteId);

        if ($entry === null) {
            Logger::debug('Index skipped: entry no longer exists', ['entryId' => $entryId, 'siteId' => $siteId]);
            return;
        }

        if ($entry->getStatus() === Entry::STATUS_DISABLED) {
            $this->deleteForEntry($entryId, $siteId);
            return;
        }

        $this->syncEntry($entry);
    }

    private function syncEntry(Entry $element): void
    {
        $elementId = (int)$element->id;
        $siteId = (int)$element->siteId;

        if (SmartSearch::getInstance()->exclusionService->isExcluded($elementId, $siteId)) {
            $this->deleteForEntry($elementId, $siteId);
            return;
        }

        $embeddingService = SmartSearch::getInstance()->embeddingService;
        $source = SmartSearch::getInstance()->vectorSource();
        $model = $source->handle();
        $dimensions = $source->dimensions($siteId);
        $language = Stemmer::resolveLanguage($siteId);

        $boostsChanged = $this->syncBoosts($elementId, $siteId, $language, $embeddingService->collectBoostRules($element));

        $text = $embeddingService->extractTextFromElement($element);
        if (trim($text) === '') {
            if ($boostsChanged) {
                $this->bumpCacheToken();
            }
            return;
        }

        $hash = hash('sha256', $text);
        $chunks = $embeddingService->chunkText($text);
        $totalChunks = count($chunks);

        if ($this->fingerprintMatches($elementId, $siteId, $hash, $totalChunks, $dimensions, $model, $language)) {
            $this->touch($elementId, $siteId, (int)$element->sectionId, $boostsChanged);
            return;
        }

        $title = (string)$element->title;
        $now = Db::prepareDateForDb(new DateTime());
        $db = Craft::$app->getDb();

        foreach ($chunks as $index => $chunkText) {
            $db->createCommand()->upsert(LocalSchema::CHUNKS_TABLE, [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'chunkIndex' => $index,
                'totalChunks' => $totalChunks,
                'sectionId' => (int)$element->sectionId,
                'title' => $title,
                'body' => $chunkText,
                'language' => $language,
                'contentHash' => $hash,
                'tokenCount' => TokenEstimator::estimateTokens($chunkText),
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ])->execute();

            $vector = self::normalise($source->generate(FieldPrefix::forEmbedding($chunkText), $siteId));
            if ($vector === []) {
                $db->createCommand()->delete(LocalSchema::VECTORS_TABLE, [
                    'elementId' => $elementId,
                    'siteId' => $siteId,
                    'chunkIndex' => $index,
                ])->execute();
                continue;
            }

            $db->createCommand()->upsert(LocalSchema::VECTORS_TABLE, [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'chunkIndex' => $index,
                'sectionId' => (int)$element->sectionId,
                'dims' => count($vector),
                'model' => $model,
                'vector' => self::pack($vector),
            ])->execute();
        }

        $this->deleteExcessChunks($elementId, $siteId, $totalChunks);
        $this->syncPostings($elementId, $siteId, $language, $title, $chunks);

        $this->bumpCacheToken();

        Logger::info('Indexed entry into the local store', [
            'entryId' => $elementId,
            'siteId' => $siteId,
            'chunks' => $totalChunks,
            'dims' => $dimensions,
        ]);
    }

    /**
     * True when this entry's local rows already describe exactly this content, at the
     * configured width, from the configured model, stemmed in the site's language.
     *
     * The language is checked because the keyword index stores stems: after a site's
     * language changes, rows stemmed the old way never match a query stemmed the new way.
     *
     * Width and model are both part of the check for the same reason: neither a dimension
     * change nor a model change rewrites what is already stored, and no re-save would
     * otherwise fix it. Leaving the model out is the worse of the two, because the rows
     * stay the right size and nothing looks wrong: the store answers queries from one
     * embedding space using vectors built in another, which is noise rather than an error.
     *
     * $dimensions of 0 means the active source produces none right now, so the entry is up to
     * date precisely when it has none. Adding a key later moves the width off 0, every zero
     * stamp stops matching, and the next reindex rewrites them with no backfill flag to keep.
     */
    private function fingerprintMatches(int $elementId, int $siteId, string $hash, int $totalChunks, int $dimensions, string $model, string $language): bool
    {
        $stored = (new Query())
            ->select(['h' => 'MAX([[contentHash]])', 'c' => 'COUNT(*)', 'l' => 'MAX([[language]])', 'lc' => 'COUNT(DISTINCT [[language]])'])
            ->from(LocalSchema::CHUNKS_TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->one();

        if (($stored['h'] ?? null) !== $hash
            || (int)($stored['c'] ?? 0) !== $totalChunks
            || (int)($stored['lc'] ?? 0) !== 1
            || $stored['l'] !== $language
        ) {
            return false;
        }

        $stamps = (new Query())
            ->select(['dims', 'model'])
            ->distinct()
            ->from(LocalSchema::VECTORS_TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->all();

        if ($dimensions === 0) {
            return $stamps === [];
        }

        return count($stamps) === 1
            && (int)$stamps[0]['dims'] === $dimensions
            && $stamps[0]['model'] === $model;
    }

    /**
     * Content unchanged: keep the rows, refresh their bookkeeping.
     *
     * Cached rankings only go stale when the entry moved section (section filters read
     * the stored sectionId) or its boost rules changed, so a full sync over unchanged
     * content leaves the caches warm.
     */
    private function touch(int $elementId, int $siteId, int $sectionId, bool $boostsChanged): void
    {
        $db = Craft::$app->getDb();
        $entry = ['elementId' => $elementId, 'siteId' => $siteId];

        $db->createCommand()->update(
            LocalSchema::CHUNKS_TABLE,
            ['dateUpdated' => Db::prepareDateForDb(new DateTime())],
            $entry,
        )->execute();

        $moved = $db->createCommand()->update(
            LocalSchema::CHUNKS_TABLE,
            ['sectionId' => $sectionId],
            ['and', $entry, ['not', ['sectionId' => $sectionId]]],
        )->execute();

        if ($moved > 0) {
            $db->createCommand()->update(LocalSchema::VECTORS_TABLE, ['sectionId' => $sectionId], $entry)->execute();
        }

        if ($moved > 0 || $boostsChanged) {
            $this->bumpCacheToken();
        }

        Logger::debug('Skipping unchanged entry in the local store', [
            'entryId' => $elementId,
            'siteId' => $siteId,
        ]);
    }

    /** Prune rows left behind by a previously longer version of the entry. */
    private function deleteExcessChunks(int $elementId, int $siteId, int $totalChunks): void
    {
        $condition = ['and', ['elementId' => $elementId, 'siteId' => $siteId], ['>=', 'chunkIndex', $totalChunks]];
        $db = Craft::$app->getDb();
        $db->createCommand()->delete(LocalSchema::CHUNKS_TABLE, $condition)->execute();
        $db->createCommand()->delete(LocalSchema::VECTORS_TABLE, $condition)->execute();
    }

    /**
     * Rebuild the entry's postings, then refresh the dictionary for exactly the terms
     * the change could have moved.
     *
     * Title postings are written for every chunk, not just the first, so each chunk
     * carries its entry's title weight.
     *
     * @param string[] $chunks
     */
    private function syncPostings(int $elementId, int $siteId, string $language, string $title, array $chunks): void
    {
        $db = Craft::$app->getDb();

        $previousTerms = (new Query())
            ->select(['term'])
            ->distinct()
            ->from(LocalSchema::POSTINGS_TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->column();

        $db->createCommand()->delete(
            LocalSchema::POSTINGS_TABLE,
            ['elementId' => $elementId, 'siteId' => $siteId],
        )->execute();

        $titlePairs = Stemmer::terms($title, $language);

        $rows = [];
        $newTerms = [];
        foreach ($chunks as $index => $chunkText) {
            foreach (['title' => $titlePairs, 'body' => Stemmer::terms(FieldPrefix::forEmbedding($chunkText), $language)] as $field => $pairs) {
                foreach (self::countTerms($pairs) as $term => $counted) {
                    $rows[] = [$siteId, $elementId, $index, $term, $counted['raw'], $field, $counted['tf']];
                    $newTerms[$term] = true;
                }
            }
        }

        if ($rows !== []) {
            $db->createCommand()->batchInsert(
                LocalSchema::POSTINGS_TABLE,
                ['siteId', 'elementId', 'chunkIndex', 'term', 'termRaw', 'field', 'tf'],
                $rows,
            )->execute();
        }

        $affected = array_values(array_unique(array_merge($previousTerms, array_keys($newTerms))));
        $this->refreshDictionaryTerms($siteId, $affected);
    }

    /**
     * Collapse ordered [raw, stem] pairs into per-stem term frequency, keeping the
     * first raw spelling seen for the typo corrector to compare against.
     *
     * The pairs come without stopwords, so those never reach the table at all. They were
     * 17% of the postings and the ten commonest terms in the index, and keeping them cost
     * space for nothing.
     *
     * @param array<array{0: string, 1: string}> $pairs
     * @return array<string, array{raw: string, tf: int}>
     */
    private static function countTerms(array $pairs): array
    {
        $counted = [];
        foreach ($pairs as [$raw, $stem]) {
            if (strlen($stem) > self::TERM_MAX_LENGTH) {
                continue;
            }
            if (isset($counted[$stem])) {
                $counted[$stem]['tf']++;
            } else {
                $counted[$stem] = ['raw' => substr($raw, 0, self::TERM_MAX_LENGTH), 'tf' => 1];
            }
        }

        return $counted;
    }

    /**
     * Recompute df for the given terms from the postings themselves, rather than
     * accumulating it on write. Accumulating inflates df every time an entry is
     * re-indexed, and here df feeds BM25's idf, where that distorts scoring.
     *
     * df counts chunks, not entries, because that is the document the postings and the
     * dictionary counts.
     *
     * @param string[] $terms
     */
    private function refreshDictionaryTerms(int $siteId, array $terms): void
    {
        $terms = array_map('strval', $terms);

        if ($terms === []) {
            return;
        }

        $db = Craft::$app->getDb();

        foreach (array_chunk($terms, self::TERM_BATCH) as $batch) {
            $dfRows = (new Query())
                ->select([
                    'term',
                    'df' => 'COUNT(DISTINCT CONCAT([[elementId]], \'-\', [[chunkIndex]]))',
                ])
                ->from(LocalSchema::POSTINGS_TABLE)
                ->where(['siteId' => $siteId, 'term' => $batch])
                ->groupBy(['term'])
                ->all();

            $vanished = array_values(array_diff($batch, array_column($dfRows, 'term')));
            if ($vanished !== []) {
                $db->createCommand()->delete(
                    LocalSchema::TERMS_TABLE,
                    ['siteId' => $siteId, 'term' => $vanished],
                )->execute();
            }

            if ($dfRows === []) {
                continue;
            }

            $this->upsertTerms($siteId, $dfRows);
        }
    }

    /**
     * Write dictionary rows as an upsert rather than delete-then-insert.
     *
     * Two reasons, both seen in practice. The primary key compares terms by the database's
     * collation, which on MySQL is accent and case insensitive, so "cafe" and "café" are
     * one key while PHP counts them as two; a plain insert of both violates the key. And
     * a delete followed by an insert is not atomic, so two queue workers indexing entries
     * that share a term could interleave into a duplicate-key failure that aborts the run.
     * An upsert converges either way.
     *
     * @param array<array{term: string, df: int|string}> $rows
     */
    private function upsertTerms(int $siteId, array $rows): void
    {
        $db = Craft::$app->getDb();
        $table = $db->quoteTableName($db->getSchema()->getRawTableName(LocalSchema::TERMS_TABLE));

        $tuples = [];
        $params = [];
        foreach (array_values($rows) as $i => $row) {
            $term = (string)$row['term'];
            $tuples[] = "(:s{$i}, :t{$i}, :l{$i}, :d{$i})";
            $params[":s{$i}"] = $siteId;
            $params[":t{$i}"] = $term;
            $params[":l{$i}"] = strlen($term);
            $params[":d{$i}"] = (int)$row['df'];
        }

        $columns = implode(', ', array_map(
            static fn(string $c): string => $db->quoteColumnName($c),
            ['siteId', 'term', 'termLength', 'df'],
        ));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES " . implode(', ', $tuples);
        $sql .= $db->getIsPgsql()
            ? ' ON CONFLICT ("siteId", "term") DO UPDATE SET "termLength" = EXCLUDED."termLength", "df" = EXCLUDED."df"'
            : ' ON DUPLICATE KEY UPDATE `termLength` = VALUES(`termLength`), `df` = VALUES(`df`)';

        $db->createCommand($sql, $params)->execute();
    }

    /**
     * Store the entry's boost rules with their phrases already tokenised and stemmed,
     * so match time does no stemming.
     *
     * @param list<array{terms: list<string>, weight: float}> $rules From EmbeddingService::collectBoostRules(), already sanitised
     * @return bool Whether the stored rules changed, and with them every cached ranking
     */
    private function syncBoosts(int $elementId, int $siteId, string $language, array $rules): bool
    {
        $rows = [];
        foreach ($rules as ['terms' => $terms, 'weight' => $weight]) {
            $phrases = [];
            $labels = [];
            foreach ($terms as $phrase) {
                $lexemes = array_column(Stemmer::tokenizeAndStem($phrase, $language), 1);
                if ($lexemes === []) {
                    continue;
                }
                $phrases[] = $lexemes;
                $labels[] = $phrase;
            }

            if ($phrases === []) {
                continue;
            }

            $rows[] = [
                substr(implode(' + ', $labels), 0, 255),
                json_encode($phrases, JSON_UNESCAPED_UNICODE),
                round($weight, 4),
            ];
        }

        $entry = ['elementId' => $elementId, 'siteId' => $siteId];
        $stored = array_map(
            static fn(array $row): array => [$row['label'], $row['terms'], round((float)$row['weight'], 4)],
            (new Query())
                ->select(['label', 'terms', 'weight'])
                ->from(LocalSchema::BOOSTS_TABLE)
                ->where($entry)
                ->orderBy(['id' => SORT_ASC])
                ->all(),
        );

        if ($stored === $rows) {
            return false;
        }

        $db = Craft::$app->getDb();
        $db->createCommand()->delete(LocalSchema::BOOSTS_TABLE, $entry)->execute();

        if ($rows !== []) {
            $db->createCommand()->batchInsert(
                LocalSchema::BOOSTS_TABLE,
                ['elementId', 'siteId', 'label', 'terms', 'weight'],
                array_map(static fn(array $row): array => [$elementId, $siteId, ...$row], $rows),
            )->execute();
        }

        return true;
    }

    /** Remove an entry from every local table. */
    public function deleteForEntry(int $elementId, int $siteId): void
    {
        $condition = ['elementId' => $elementId, 'siteId' => $siteId];
        $db = Craft::$app->getDb();

        $terms = (new Query())
            ->select(['term'])
            ->distinct()
            ->from(LocalSchema::POSTINGS_TABLE)
            ->where($condition)
            ->column();

        foreach (array_diff(LocalSchema::ALL, [LocalSchema::TERMS_TABLE]) as $table) {
            $db->createCommand()->delete($table, $condition)->execute();
        }

        $this->refreshDictionaryTerms($siteId, $terms);

        $this->bumpCacheToken();
    }

    /** Empty the local store, keeping the tables. */
    public function clearAll(?int $siteId = null): int
    {
        $condition = $siteId !== null ? ['siteId' => $siteId] : [];
        $db = Craft::$app->getDb();

        $chunks = 0;
        foreach (LocalSchema::ALL as $table) {
            $deleted = $db->createCommand()->delete($table, $condition)->execute();
            if ($table === LocalSchema::CHUNKS_TABLE) {
                $chunks = $deleted;
            }
        }

        $this->bumpCacheToken();
        Logger::info('Cleared the local store', ['siteId' => $siteId, 'chunks' => $chunks]);

        return $chunks;
    }

    /**
     * Re-tokenise the postings from the chunk text already stored, without touching the
     * vectors.
     *
     * A tokeniser change (a new stopword list, a different stemmer) invalidates the
     * postings but not the embeddings, and the content hash has not moved, so an ordinary
     * reindex would skip the entry entirely and a wiping one would re-embed the whole
     * corpus to fix a text change. This does neither, and costs no API calls.
     *
     * @return int Entries rebuilt
     */
    public function rebuildPostings(?int $siteId = null): int
    {
        $entries = (new Query())
            ->select(['elementId', 'siteId'])
            ->distinct()
            ->from(LocalSchema::CHUNKS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->orderBy(['siteId' => SORT_ASC, 'elementId' => SORT_ASC])
            ->all();

        $rebuilt = 0;
        foreach ($entries as $entry) {
            $elementId = (int)$entry['elementId'];
            $entrySiteId = (int)$entry['siteId'];

            $chunks = (new Query())
                ->select(['chunkIndex', 'title', 'body', 'language'])
                ->from(LocalSchema::CHUNKS_TABLE)
                ->where(['elementId' => $elementId, 'siteId' => $entrySiteId])
                ->orderBy(['chunkIndex' => SORT_ASC])
                ->all();

            $this->syncPostings(
                $elementId,
                $entrySiteId,
                (string)$chunks[0]['language'],
                (string)$chunks[0]['title'],
                array_map(static fn(array $c): string => (string)$c['body'], $chunks),
            );

            $rebuilt++;
        }

        $this->bumpCacheToken();
        Logger::info('Rebuilt the local postings', ['siteId' => $siteId, 'entries' => $rebuilt]);

        return $rebuilt;
    }

    /**
     * Rebuild the whole dictionary from the postings. The incremental path is exact, so
     * this is a repair tool rather than a routine step: for a store written before a
     * tokeniser change, or one whose postings were edited out of band.
     */
    public function rebuildDictionary(?int $siteId = null): int
    {
        $sites = $siteId !== null
            ? [$siteId]
            : array_map('intval', (new Query())
                ->select(['siteId'])
                ->distinct()
                ->from(LocalSchema::POSTINGS_TABLE)
                ->column());

        $total = 0;
        foreach ($sites as $site) {
            Craft::$app->getDb()->createCommand()
                ->delete(LocalSchema::TERMS_TABLE, ['siteId' => $site])
                ->execute();

            $terms = (new Query())
                ->select(['term'])
                ->distinct()
                ->from(LocalSchema::POSTINGS_TABLE)
                ->where(['siteId' => $site])
                ->column();

            $this->refreshDictionaryTerms($site, $terms);
            $total += count($terms);
        }

        $this->bumpCacheToken();
        Logger::info('Rebuilt the local dictionary', ['siteId' => $siteId, 'terms' => $total]);

        return $total;
    }

    /**
     * Per-entry index summary for the local store, keyed "elementId-siteId".
     *
     * IndexInspectionService counts coverage from this without knowing which engine
     * produced it. Shape has to match exactly: chunkCount plus the newest dateUpdated
     * across the entry's chunks, which is what "stale" is decided against.
     *
     * @return array<string, array{chunkCount: int, lastIndexed: string}>
     */
    public function getIndexedSummary(?int $siteId = null): array
    {
        $rows = (new Query())
            ->select([
                'elementId',
                'siteId',
                'chunkCount' => 'COUNT(*)',
                'lastIndexed' => 'MAX([[dateUpdated]])',
            ])
            ->from(LocalSchema::CHUNKS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['elementId', 'siteId'])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['elementId'] . '-' . $row['siteId']] = [
                'chunkCount' => (int)$row['chunkCount'],
                'lastIndexed' => (string)$row['lastIndexed'],
            ];
        }
        return $map;
    }

    /**
     * One entry's stored chunks, oldest chunk index first, for the CP inspection pane.
     *
     * @return list<array{chunkIndex: int, totalChunks: int, title: ?string, body: ?string, dateUpdated: string}>
     */
    public function getChunksForElement(int $elementId, int $siteId): array
    {
        return (new Query())
            ->select(['chunkIndex', 'totalChunks', 'title', 'body', 'dateUpdated'])
            ->from(LocalSchema::CHUNKS_TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->orderBy(['chunkIndex' => SORT_ASC])
            ->all();
    }

    /**
     * One entry's boost rules, heaviest first, for the CP inspection pane.
     *
     * @return list<array{label: string, weight: float}>
     */
    public function getBoostRulesForElement(int $elementId, int $siteId): array
    {
        return (new Query())
            ->select(['label', 'weight'])
            ->from(LocalSchema::BOOSTS_TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->orderBy(['weight' => SORT_DESC])
            ->all();
    }

    /**
     * Everything the dashboard meter and the console report need, per site.
     *
     * Vector bytes are computed as rows x dims x 4: every vector row is fixed width, so
     * the arithmetic is exact and needs no per-driver query.
     *
     * @return array<string, mixed>
     */
    public function stats(?int $siteId = null): array
    {
        $source = SmartSearch::getInstance()->vectorSource();
        $dimensions = SmartSearch::getInstance()->getSettings()->dimensions;
        $model = $source->handle();

        $chunkRows = (new Query())
            ->select([
                'siteId',
                'chunks' => 'COUNT(*)',
                'entries' => 'COUNT(DISTINCT [[elementId]])',
                'avgTokens' => 'AVG([[tokenCount]])',
                'lastIndexed' => 'MAX([[dateUpdated]])',
            ])
            ->from(LocalSchema::CHUNKS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['siteId'])
            ->all();

        $vectorRows = (new Query())
            ->select(['siteId', 'dims', 'model', 'rows' => 'COUNT(*)'])
            ->from(LocalSchema::VECTORS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['siteId', 'dims', 'model'])
            ->all();

        $postingRows = (new Query())
            ->select(['siteId', 'postings' => 'COUNT(*)'])
            ->from(LocalSchema::POSTINGS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['siteId'])
            ->all();

        $termRows = (new Query())
            ->select(['siteId', 'terms' => 'COUNT(*)'])
            ->from(LocalSchema::TERMS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['siteId'])
            ->all();

        $siteRow = static fn(int $site, int $entries = 0, int $chunks = 0, float $avgTokens = 0.0, ?string $lastIndexed = null): array => [
            'siteId' => $site,
            'name' => BaseEngine::siteName($site),
            'entries' => $entries,
            'chunks' => $chunks,
            'avgTokens' => $avgTokens,
            'lastIndexed' => $lastIndexed,
            'vectors' => 0,
            'staleVectors' => 0,
            'vectorBytes' => 0,
            'postings' => 0,
            'terms' => 0,
        ];

        $sites = [];
        foreach ($chunkRows as $row) {
            $site = (int)$row['siteId'];
            $sites[$site] = $siteRow($site, (int)$row['entries'], (int)$row['chunks'], round((float)$row['avgTokens'], 1), $row['lastIndexed']);
        }

        foreach ($vectorRows as $row) {
            $site = (int)$row['siteId'];
            $sites[$site] ??= $siteRow($site);
            $rows = (int)$row['rows'];
            $sites[$site]['vectorBytes'] += $rows * (int)$row['dims'] * 4;
            if ((int)$row['dims'] === $dimensions && $row['model'] === $model) {
                $sites[$site]['vectors'] += $rows;
            } else {
                $sites[$site]['staleVectors'] += $rows;
            }
        }

        foreach ([[$postingRows, 'postings'], [$termRows, 'terms']] as [$rows, $key]) {
            foreach ($rows as $row) {
                $site = (int)$row['siteId'];
                if (isset($sites[$site])) {
                    $sites[$site][$key] = (int)$row[$key];
                }
            }
        }

        $totals = ['entries' => 0, 'chunks' => 0, 'vectors' => 0, 'staleVectors' => 0, 'vectorBytes' => 0, 'postings' => 0, 'terms' => 0];
        foreach ($sites as $site) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $site[$key];
            }
        }

        $lastIndexed = null;
        foreach ($sites as $site) {
            if ($site['lastIndexed'] !== null && ($lastIndexed === null || $site['lastIndexed'] > $lastIndexed)) {
                $lastIndexed = $site['lastIndexed'];
            }
        }

        return [
            'dimensions' => $dimensions,
            'bytesPerChunk' => $dimensions * 4,
            'sites' => array_values($sites),
            'totals' => $totals,
            'entryCount' => $totals['entries'],
            'chunkCount' => $totals['chunks'],
            'lastIndexed' => $lastIndexed,
            'dbCacheBytes' => self::databaseCacheBytes(),
            'scanWarnMs' => LocalSearchService::SCAN_WARN_MS,
            'scanP95Ms' => LocalSearchService::scanP95(),
            'scanMsPerChunk' => LocalSearchService::scanMsPerChunk(),
            'scanSampleCount' => count(LocalSearchService::scanSamples()),
        ];
    }

    /**
     * How much memory the database keeps pages in, so the meter can say when the vector
     * table stops fitting in it: the point where every scan starts hitting disk, which
     * is the real cliff rather than the row count.
     *
     * Null when the server reports none, and the meter hides the row.
     */
    private static function databaseCacheBytes(): ?int
    {
        $db = Craft::$app->getDb();

        if ($db->getIsPgsql()) {
            $blocks = (int)$db->createCommand('SELECT setting FROM pg_settings WHERE name = \'shared_buffers\'')->queryScalar();
            return $blocks > 0 ? $blocks * 8192 : null;
        }

        $bytes = (int)$db->createCommand('SELECT @@innodb_buffer_pool_size')->queryScalar();
        return $bytes > 0 ? $bytes : null;
    }

    /**
     * Callers pass normalise()'d vectors, so cosine similarity is a plain dot product at
     * search time. OpenAI already returns unit vectors; normalising anyway means the scan
     * stays correct for any provider that does not.
     *
     * @param float[] $vector
     */
    public static function pack(array $vector): string
    {
        return pack(self::PACK_FORMAT, ...$vector);
    }

    /**
     * The inverse of pack(), keyed from 1 the way unpack() returns it, so the scan's hot
     * loop indexes it without a copy.
     *
     * @return array<int, float>
     */
    public static function unpack(string $packed): array
    {
        return unpack(self::PACK_FORMAT, $packed) ?: [];
    }

    /**
     * Unit-normalise a vector so cosine similarity is a plain dot product. Empty for a zero
     * vector, which has no direction to compare.
     *
     * @param float[] $vector
     * @return float[]
     */
    public static function normalise(array $vector): array
    {
        $norm = 0.0;
        foreach ($vector as $component) {
            $norm += $component * $component;
        }

        if ($norm <= 0.0) {
            return [];
        }

        $norm = sqrt($norm);
        foreach ($vector as $i => $component) {
            $vector[$i] = $component / $norm;
        }

        return $vector;
    }

    /** Identifies the current contents of the local store; see CacheTag::token(). */
    public function cacheToken(): string
    {
        return CacheTag::token(self::CACHE_TOKEN_KEY);
    }

    /** Called by every path that changes what a local search would return. */
    public function bumpCacheToken(): void
    {
        Craft::$app->getCache()->delete(self::CACHE_TOKEN_KEY);
        CacheTag::invalidateResults();
    }
}
