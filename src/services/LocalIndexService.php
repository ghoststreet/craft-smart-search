<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\Stemmer;
use ghoststreet\craftsmartsearch\helpers\TokenEstimator;
use ghoststreet\craftsmartsearch\migrations\Install as LocalTables;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\base\Component;

/**
 * Write side of the `local` search type: chunks, vectors, postings, dictionary and
 * boost rules, all in Craft's own database.
 *
 * Mirrors EmbeddingService::indexElement() step for step — same text extraction, same
 * chunker, same content hash, same short-circuit — so the two indexes hold the same
 * content and a quality difference between the types is attributable to their storage
 * and scoring rather than to what got indexed.
 *
 * Reached only from the Local* jobs, never from the pgvector write path, so a fault
 * here cannot reach that store.
 */
class LocalIndexService extends Component
{
    private static ?bool $chunksMb4 = null;

    /** Vectors are packed little-endian explicitly, so a dump moves between machines. */
    public const PACK_FORMAT = 'g';

    private const CACHE_TOKEN_KEY = 'smart_search_local_token';

    /** IN-list chunking for the dictionary refresh. */
    private const TERM_BATCH = 500;

    /**
     * Index one entry into the local store.
     *
     * @throws Throwable Only from the callers' perspective; the jobs log and swallow.
     */
    public function syncEntry(ElementInterface $element): void
    {
        if (!($element instanceof Entry)) {
            return;
        }

        $elementId = (int)$element->id;
        $siteId = (int)$element->siteId;

        if (SmartSearch::getInstance()->exclusionService->isExcluded($elementId, $siteId)) {
            $this->deleteForEntry($elementId, $siteId);
            return;
        }

        if ($element->getUrl() === null) {
            return;
        }

        $embeddingService = SmartSearch::getInstance()->embeddingService;
        $settings = SmartSearch::getInstance()->getSettings();
        $language = KeywordSearchService::resolveLanguage($siteId);

        /* Boost rules come from the same event the pgvector store listens to, and are
           written before the text guard so an entry with no extractable text still
           carries its rules. */
        $this->syncBoosts($elementId, $siteId, $language, $embeddingService->collectBoostRules($element));

        $text = $this->storable($embeddingService->extractTextFromElement($element), $elementId, $siteId);
        if (trim($text) === '') {
            return;
        }

        $hash = hash('sha256', $text);
        $chunks = $embeddingService->chunkText($text);
        $totalChunks = count($chunks);

        if ($this->fingerprintMatches($elementId, $siteId, $hash, $totalChunks, $settings->localDimensions)) {
            $this->touch($elementId, $siteId, (int)$element->sectionId);
            return;
        }

        $title = $this->storable((string)($element->title ?? ''), $elementId, $siteId);
        $dimensions = $settings->localDimensions;
        $now = Db::prepareDateForDb(new DateTime());
        $db = Craft::$app->getDb();

        foreach ($chunks as $index => $chunkText) {
            /* Its own embedding request at its own width, cached under its own key.
               Nothing is derived from the pgvector vector. */
            $vector = $embeddingService->generateEmbedding($chunkText, $settings->localEmbeddingModel, $dimensions);

            $db->createCommand()->upsert(LocalTables::CHUNKS_TABLE, [
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

            /* One row at a time rather than batchInsert: the query builder quotes batch
               values into SQL literals, which is the wrong handling for a blob. */
            $db->createCommand()->upsert(LocalTables::VECTORS_TABLE, [
                'elementId' => $elementId,
                'siteId' => $siteId,
                'chunkIndex' => $index,
                'sectionId' => (int)$element->sectionId,
                'dims' => count($vector),
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
     * Drops characters the chunks table cannot store, so one of them costs a character
     * rather than the entry.
     *
     * Install creates these tables as utf8mb4 and is the real fix. This is the net under
     * it: an existing table's charset depends on the site's history, and a 4-byte
     * character reaching a 3-byte column is a hard MySQL error that aborts the insert.
     * Stripped rather than encoded as an HTML entity the way Craft does it, because the
     * entity would enter the inverted index as a term that can never match.
     */
    private function storable(string $text, int $elementId, int $siteId): string
    {
        if ($text === '') {
            return $text;
        }

        if (!self::chunksSupportMb4() && StringHelper::containsMb4($text)) {
            $dropped = 0;
            $text = StringHelper::replaceMb4($text, static function() use (&$dropped): string {
                $dropped++;
                return '';
            });

            Logger::warning('Dropped 4-byte characters the local index cannot store', [
                'entryId' => $elementId,
                'siteId' => $siteId,
                'dropped' => $dropped,
                'fix' => 'reinstall the plugin so its tables are created as utf8mb4',
            ]);
        }

        return $text;
    }

    /** Cached: one schema read per request, not one per entry. */
    private static function chunksSupportMb4(): bool
    {
        if (self::$chunksMb4 === null) {
            $db = Craft::$app->getDb();
            self::$chunksMb4 = !$db->getIsMysql()
                || $db->getSchema()->supportsMb4($db->getSchema()->getRawTableName(LocalTables::CHUNKS_TABLE));
        }

        return self::$chunksMb4;
    }

    /**
     * True when this entry's local rows already describe exactly this content at the
     * configured width. The width is part of the check because a dimension change
     * leaves stored vectors at their old width, which no re-save would otherwise fix.
     */
    private function fingerprintMatches(int $elementId, int $siteId, string $hash, int $totalChunks, int $dimensions): bool
    {
        $stored = (new Query())
            ->select(['h' => 'MAX([[contentHash]])', 'c' => 'COUNT(*)'])
            ->from(LocalTables::CHUNKS_TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->one();

        if (($stored['h'] ?? null) !== $hash || (int)($stored['c'] ?? 0) !== $totalChunks) {
            return false;
        }

        $widths = (new Query())
            ->select(['dims'])
            ->distinct()
            ->from(LocalTables::VECTORS_TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->column();

        return array_map('intval', $widths) === [$dimensions];
    }

    /** Content unchanged: keep the rows, refresh their bookkeeping. */
    private function touch(int $elementId, int $siteId, int $sectionId): void
    {
        Craft::$app->getDb()->createCommand()->update(
            LocalTables::CHUNKS_TABLE,
            ['sectionId' => $sectionId, 'dateUpdated' => Db::prepareDateForDb(new DateTime())],
            ['elementId' => $elementId, 'siteId' => $siteId],
        )->execute();

        Craft::$app->getDb()->createCommand()->update(
            LocalTables::VECTORS_TABLE,
            ['sectionId' => $sectionId],
            ['elementId' => $elementId, 'siteId' => $siteId],
        )->execute();

        $this->bumpCacheToken();

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
        $db->createCommand()->delete(LocalTables::CHUNKS_TABLE, $condition)->execute();
        $db->createCommand()->delete(LocalTables::VECTORS_TABLE, $condition)->execute();
    }

    /**
     * Rebuild the entry's postings, then refresh the dictionary for exactly the terms
     * the change could have moved.
     *
     * Title postings are written for every chunk, not just the first, because that is
     * what the pgvector `tsv` does: `setweight(A, title) || setweight(B, body)` on each
     * chunk row. Matching it is what makes the two types' title weighting comparable.
     *
     * @param string[] $chunks
     */
    private function syncPostings(int $elementId, int $siteId, string $language, string $title, array $chunks): void
    {
        $db = Craft::$app->getDb();

        $previousTerms = (new Query())
            ->select(['term'])
            ->distinct()
            ->from(LocalTables::POSTINGS_TABLE)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->column();

        $db->createCommand()->delete(
            LocalTables::POSTINGS_TABLE,
            ['elementId' => $elementId, 'siteId' => $siteId],
        )->execute();

        $titlePairs = Stemmer::tokenizeAndStem($title, $language);

        $rows = [];
        $newTerms = [];
        foreach ($chunks as $index => $chunkText) {
            foreach (['title' => $titlePairs, 'body' => Stemmer::tokenizeAndStem($chunkText, $language)] as $field => $pairs) {
                foreach (self::countTerms($pairs, $language) as $term => $counted) {
                    $rows[] = [$siteId, $elementId, $index, $term, $counted['raw'], $field, $counted['tf']];
                    $newTerms[$term] = true;
                }
            }
        }

        if ($rows !== []) {
            $db->createCommand()->batchInsert(
                LocalTables::POSTINGS_TABLE,
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
     * Stopwords are dropped here rather than at search time, so they never reach the
     * table at all. They were 17% of the postings and the ten commonest terms in the
     * index, and Postgres's text search config discards them too, so keeping them cost
     * space and comparability for nothing.
     *
     * @param array<array{0: string, 1: string}> $pairs
     * @return array<string, array{raw: string, tf: int}>
     */
    private static function countTerms(array $pairs, string $language): array
    {
        $counted = [];
        foreach ($pairs as [$raw, $stem]) {
            if ($stem === '' || strlen($stem) > 100 || Stemmer::isStopword($raw, $language)) {
                continue;
            }
            if (isset($counted[$stem])) {
                $counted[$stem]['tf']++;
            } else {
                $counted[$stem] = ['raw' => substr($raw, 0, 100), 'tf' => 1];
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
     * pgvector `ts_stat` dictionary both count.
     *
     * @param string[] $terms
     */
    private function refreshDictionaryTerms(int $siteId, array $terms): void
    {
        /*
         * Cast every term to a string. A term like "2026" arrives here as a PHP int,
         * because an array key that looks like an integer becomes one, and a mixed
         * int/string IN list makes MySQL compare a varchar column numerically — which
         * then fails on the first non-numeric term in the table, not on the numeric one.
         */
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
                ->from(LocalTables::POSTINGS_TABLE)
                ->where(['siteId' => $siteId, 'term' => $batch])
                ->groupBy(['term'])
                ->all();

            /* Only terms that no longer appear in any posting are removed. */
            $vanished = array_values(array_diff($batch, array_column($dfRows, 'term')));
            if ($vanished !== []) {
                $db->createCommand()->delete(
                    LocalTables::TERMS_TABLE,
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
     * one key while PHP counts them as two — a plain insert of both violates the key. And
     * a delete followed by an insert is not atomic, so two queue workers indexing entries
     * that share a term could interleave into a duplicate-key failure that aborts the run.
     * An upsert converges either way.
     *
     * @param array<array{term: string, df: int|string}> $rows
     */
    private function upsertTerms(int $siteId, array $rows): void
    {
        $db = Craft::$app->getDb();
        $table = $db->quoteTableName($db->getSchema()->getRawTableName(LocalTables::TERMS_TABLE));

        $tuples = [];
        $params = [];
        foreach (array_values($rows) as $i => $row) {
            $term = (string)$row['term'];
            $tuples[] = "(:s{$i}, :t{$i}, :l{$i}, :d{$i})";
            $params[":s{$i}"] = $siteId;
            $params[":t{$i}"] = $term;
            /* Byte length, matching PHP's byte-based levenshtein(), so the corrector's
               length band actually contains its candidates. */
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
     * Every field is read defensively because the rules come from a developer's own
     * EVENT_INDEX_BOOSTS listener, which is why the parameter is typed as unvalidated
     * rather than as the shape it is documented to return. The intended shape is
     * `array{terms: string[], weight: int|float}` per rule.
     *
     * @param array<array-key, mixed> $rules
     */
    private function syncBoosts(int $elementId, int $siteId, string $language, array $rules): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete(
            LocalTables::BOOSTS_TABLE,
            ['elementId' => $elementId, 'siteId' => $siteId],
        )->execute();

        $rows = [];
        foreach ($rules as $rule) {
            $terms = is_array($rule['terms'] ?? null) ? $rule['terms'] : [];
            $weight = (float)($rule['weight'] ?? 0);

            $phrases = [];
            $labels = [];
            foreach ($terms as $phrase) {
                $phrase = trim((string)$phrase);
                if ($phrase === '') {
                    continue;
                }
                $lexemes = array_column(Stemmer::tokenizeAndStem($phrase, $language), 1);
                if ($lexemes === []) {
                    continue;
                }
                $phrases[] = $lexemes;
                $labels[] = $this->storable($phrase, $elementId, $siteId);
            }

            if ($phrases === [] || $weight <= 0) {
                continue;
            }

            $rows[] = [
                $elementId,
                $siteId,
                substr(implode(' + ', $labels), 0, 255),
                json_encode($phrases, JSON_UNESCAPED_UNICODE),
                $weight,
            ];
        }

        if ($rows !== []) {
            $db->createCommand()->batchInsert(
                LocalTables::BOOSTS_TABLE,
                ['elementId', 'siteId', 'label', 'terms', 'weight'],
                $rows,
            )->execute();
        }
    }

    /** Remove an entry from every local table. */
    public function deleteForEntry(int $elementId, ?int $siteId = null): void
    {
        $condition = ['elementId' => $elementId];
        if ($siteId !== null) {
            $condition['siteId'] = $siteId;
        }

        $db = Craft::$app->getDb();

        /* Read the vocabulary before dropping the rows that define it. */
        $terms = (new Query())
            ->select(['term'])
            ->distinct()
            ->from(LocalTables::POSTINGS_TABLE)
            ->where($condition)
            ->column();

        $sites = $siteId !== null
            ? [$siteId]
            : array_map('intval', (new Query())
                ->select(['siteId'])
                ->distinct()
                ->from(LocalTables::CHUNKS_TABLE)
                ->where($condition)
                ->column());

        foreach ([
            LocalTables::BOOSTS_TABLE,
            LocalTables::POSTINGS_TABLE,
            LocalTables::VECTORS_TABLE,
            LocalTables::CHUNKS_TABLE,
        ] as $table) {
            $db->createCommand()->delete($table, $condition)->execute();
        }

        foreach ($sites as $site) {
            $this->refreshDictionaryTerms((int)$site, $terms);
        }

        $this->bumpCacheToken();
    }

    /** Empty the local store, keeping the tables. */
    public function clearAll(?int $siteId = null): int
    {
        $condition = $siteId !== null ? ['siteId' => $siteId] : [];
        $db = Craft::$app->getDb();

        $chunks = 0;
        foreach ([
            LocalTables::BOOSTS_TABLE,
            LocalTables::POSTINGS_TABLE,
            LocalTables::VECTORS_TABLE,
            LocalTables::TERMS_TABLE,
            LocalTables::CHUNKS_TABLE,
        ] as $table) {
            $deleted = $db->createCommand()->delete($table, $condition)->execute();
            if ($table === LocalTables::CHUNKS_TABLE) {
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
     * A tokeniser change — a new stopword list, a different stemmer — invalidates the
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
            ->from(LocalTables::CHUNKS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->orderBy(['siteId' => SORT_ASC, 'elementId' => SORT_ASC])
            ->all();

        $rebuilt = 0;
        foreach ($entries as $entry) {
            $elementId = (int)$entry['elementId'];
            $entrySiteId = (int)$entry['siteId'];

            $chunks = (new Query())
                ->select(['chunkIndex', 'title', 'body', 'language'])
                ->from(LocalTables::CHUNKS_TABLE)
                ->where(['elementId' => $elementId, 'siteId' => $entrySiteId])
                ->orderBy(['chunkIndex' => SORT_ASC])
                ->all();

            if ($chunks === []) {
                continue;
            }

            $this->syncPostings(
                $elementId,
                $entrySiteId,
                (string)($chunks[0]['language'] ?? 'simple'),
                (string)($chunks[0]['title'] ?? ''),
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
     * this is a repair tool rather than a routine step — for a store written before a
     * tokeniser change, or one whose postings were edited out of band.
     */
    public function rebuildDictionary(?int $siteId = null): int
    {
        $sites = $siteId !== null
            ? [$siteId]
            : array_map('intval', (new Query())
                ->select(['siteId'])
                ->distinct()
                ->from(LocalTables::POSTINGS_TABLE)
                ->column());

        $total = 0;
        foreach ($sites as $site) {
            Craft::$app->getDb()->createCommand()
                ->delete(LocalTables::TERMS_TABLE, ['siteId' => $site])
                ->execute();

            $terms = (new Query())
                ->select(['term'])
                ->distinct()
                ->from(LocalTables::POSTINGS_TABLE)
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
     * Everything the dashboard meter and the console report need, per site.
     *
     * Vector bytes are computed as rows x dims x 4 rather than read from
     * information_schema or pg_total_relation_size: every vector row is fixed width, so
     * the arithmetic is exact and needs no per-driver query.
     *
     * @return array<string, mixed>
     */
    /**
     * Per-entry index summary for the local store, keyed "elementId-siteId".
     *
     * The twin of DatabaseService::getIndexedSummary, so the coverage counting in
     * IndexInspectionService can be fed either store without knowing which one it has.
     * Shape has to match exactly: chunkCount plus the newest dateUpdated across the
     * entry's chunks, which is what "stale" is decided against.
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
            ->from(LocalTables::CHUNKS_TABLE)
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

    public function stats(?int $siteId = null): array
    {
        $settings = SmartSearch::getInstance()->getSettings();
        $dimensions = $settings->localDimensions;

        $chunkRows = (new Query())
            ->select([
                'siteId',
                'chunks' => 'COUNT(*)',
                'entries' => 'COUNT(DISTINCT [[elementId]])',
                'avgTokens' => 'AVG([[tokenCount]])',
                'lastIndexed' => 'MAX([[dateUpdated]])',
            ])
            ->from(LocalTables::CHUNKS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['siteId'])
            ->all();

        $vectorRows = (new Query())
            ->select(['siteId', 'dims', 'rows' => 'COUNT(*)'])
            ->from(LocalTables::VECTORS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['siteId', 'dims'])
            ->all();

        $postingRows = (new Query())
            ->select(['siteId', 'postings' => 'COUNT(*)'])
            ->from(LocalTables::POSTINGS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['siteId'])
            ->all();

        $termRows = (new Query())
            ->select(['siteId', 'terms' => 'COUNT(*)'])
            ->from(LocalTables::TERMS_TABLE)
            ->andFilterWhere(['siteId' => $siteId])
            ->groupBy(['siteId'])
            ->all();

        $sites = [];
        foreach ($chunkRows as $row) {
            $site = (int)$row['siteId'];
            $sites[$site] = [
                'siteId' => $site,
                'name' => self::siteName($site),
                'entries' => (int)$row['entries'],
                'chunks' => (int)$row['chunks'],
                'avgTokens' => round((float)$row['avgTokens'], 1),
                'lastIndexed' => $row['lastIndexed'],
                'vectors' => 0,
                /* Rows written at a width other than the current setting. The scan skips
                   them, so they are invisible to search until a reindex. */
                'staleVectors' => 0,
                'vectorBytes' => 0,
                'postings' => 0,
                'terms' => 0,
            ];
        }

        foreach ($vectorRows as $row) {
            $site = (int)$row['siteId'];
            /* Seed from the vectors too: a vector with no chunk row is an orphan, and
               hiding it is the opposite of what a diagnostic should do. */
            $sites[$site] ??= [
                'siteId' => $site,
                'name' => self::siteName($site),
                'entries' => 0, 'chunks' => 0, 'avgTokens' => 0.0, 'lastIndexed' => null,
                'vectors' => 0, 'staleVectors' => 0, 'vectorBytes' => 0, 'postings' => 0, 'terms' => 0,
            ];
            $rows = (int)$row['rows'];
            $sites[$site]['vectorBytes'] += $rows * (int)$row['dims'] * 4;
            if ((int)$row['dims'] === $dimensions) {
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

        return [
            'dimensions' => $dimensions,
            'bytesPerChunk' => $dimensions * 4,
            'sites' => array_values($sites),
            'totals' => $totals,
            'dbCacheBytes' => self::databaseCacheBytes(),
            'scanP95Ms' => LocalSearchService::scanPercentile(95),
            'scanMsPerChunk' => LocalSearchService::scanMsPerChunk(),
            'scanSampleCount' => count(LocalSearchService::scanSamples()),
        ];
    }

    /** A site's name can be an empty string, which is no use as a label. */
    private static function siteName(int $siteId): string
    {
        $name = Craft::$app->getSites()->getSiteById($siteId)?->name ?? '';

        return trim($name) !== '' ? $name : "site {$siteId}";
    }

    /**
     * How much memory the database keeps pages in, so the meter can say when the vector
     * table stops fitting in it — the point where every scan starts hitting disk, which
     * is the real cliff rather than the row count.
     *
     * Best effort: null whenever the server will not answer, and the meter hides the row.
     */
    private static function databaseCacheBytes(): ?int
    {
        $db = Craft::$app->getDb();

        try {
            if ($db->getIsPgsql()) {
                /* shared_buffers is reported in 8kB blocks. */
                $blocks = (int)$db->createCommand('SELECT setting FROM pg_settings WHERE name = \'shared_buffers\'')->queryScalar();
                return $blocks > 0 ? $blocks * 8192 : null;
            }

            $bytes = (int)$db->createCommand('SELECT @@innodb_buffer_pool_size')->queryScalar();
            return $bytes > 0 ? $bytes : null;
        } catch (Throwable $e) {
            Logger::debug('Could not read the database cache size', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Unit-normalise then pack, so cosine similarity is a plain dot product at search
     * time. OpenAI already returns unit vectors; doing it here anyway means the scan
     * stays correct for any provider that does not.
     *
     * @param float[] $vector
     */
    public static function pack(array $vector): string
    {
        $norm = 0.0;
        foreach ($vector as $component) {
            $norm += $component * $component;
        }
        $norm = sqrt($norm);

        if ($norm > 0.0) {
            foreach ($vector as $i => $component) {
                $vector[$i] = $component / $norm;
            }
        }

        return pack(self::PACK_FORMAT . '*', ...$vector);
    }

    /**
     * Opaque token identifying the current contents of the local store. Anything derived
     * from a local search keys on it, so a write invalidates those entries immediately
     * rather than leaving them to expire.
     *
     * Random rather than a counter, so an evicted key cannot resurrect entries cached
     * under an earlier value.
     */
    public function cacheToken(): string
    {
        $cache = Craft::$app->getCache();
        $token = $cache->get(self::CACHE_TOKEN_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(8));
            $cache->set(self::CACHE_TOKEN_KEY, $token, 0, CacheTag::dependency());
        }

        return $token;
    }

    /** Called by every path that changes what a local search would return. */
    public function bumpCacheToken(): void
    {
        Craft::$app->getCache()->delete(self::CACHE_TOKEN_KEY);
    }
}
