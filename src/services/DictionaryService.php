<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\jobs\RebuildDictionaryJob;
use ghoststreet\craftsmartsearch\SmartSearch;
use PDOException;
use Throwable;
use yii\base\Component;

/**
 * Owns the typo-tolerance corpus dictionary: a flat (term, df) table populated
 * from the vectors-table's existing tsvector lexemes via `ts_stat()`.
 *
 * Pattern matches the rest of the plugin: never throws on missing capability
 * (pg_trgm extension, table absence) — callers use isAvailable() to gate.
 *
 * Schema lives in the vectors database, so this class also handles idempotent
 * provisioning (ensureSchema) that swallows permission failures gracefully —
 * a DB user without CREATE EXTENSION rights simply runs without typo tolerance.
 */
class DictionaryService extends Component
{
    public const TERMS_TABLE = 'smart_search_terms';

    private const REQUEST_CACHE_TTL_SECONDS = 60;

    /** Coalesce window — repeated requests inside this window enqueue at most one rebuild. */
    private const REBUILD_COALESCE_TTL_SECONDS = 600;

    private const REBUILD_PENDING_CACHE_KEY = 'smart_search_dictionary_rebuild_pending';

    private ?bool $availabilityCache = null;

    /**
     * Coalesced rebuild request — used from indexing/deletion hooks. Pushes at
     * most one RebuildDictionaryJob per 10-minute window so a bulk reindex of
     * thousands of entries produces a single rebuild job, not thousands.
     *
     * The job clears the marker on execute() so the next change can re-queue.
     */
    public function requestRebuild(): void
    {
        if (!SmartSearch::getInstance()->getSettings()->enableTypoTolerance) {
            return;
        }

        $cache = Craft::$app->getCache();
        if ($cache->get(self::REBUILD_PENDING_CACHE_KEY) !== false) {
            return;
        }

        $cache->set(self::REBUILD_PENDING_CACHE_KEY, 1, self::REBUILD_COALESCE_TTL_SECONDS);
        Craft::$app->getQueue()->push(new RebuildDictionaryJob());
    }

    public function clearRebuildPendingMarker(): void
    {
        Craft::$app->getCache()->delete(self::REBUILD_PENDING_CACHE_KEY);
    }

    public function getTermCount(): int
    {
        try {
            $db = SmartSearch::getInstance()->databaseService->getConnection();
            $stmt = $db->query("SELECT COUNT(*) FROM " . $this->qualifiedTermsTable());
            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function hasTrgmExtension(): bool
    {
        return $this->hasExtension('pg_trgm');
    }

    /**
     * True only when pg_trgm is installed and the dictionary table has rows.
     * Cached per-request and via Craft cache for 60s to avoid hammering pg_extension.
     */
    public function isAvailable(): bool
    {
        if ($this->availabilityCache !== null) {
            return $this->availabilityCache;
        }

        $cache = Craft::$app->getCache();
        $cacheKey = 'smart_search_typo_available';
        $cached = $cache->get($cacheKey);
        if ($cached !== false) {
            return $this->availabilityCache = (bool)$cached;
        }

        $available = $this->checkAvailability();
        $cache->set($cacheKey, $available ? 1 : 0, self::REQUEST_CACHE_TTL_SECONDS);
        return $this->availabilityCache = $available;
    }

    public function hasFuzzyStrMatch(): bool
    {
        return $this->hasExtension('fuzzystrmatch');
    }

    private function hasExtension(string $name): bool
    {
        try {
            $db = SmartSearch::getInstance()->databaseService->getConnection();
            $stmt = $db->prepare("SELECT 1 FROM pg_extension WHERE extname = :name");
            $stmt->execute([':name' => $name]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function checkAvailability(): bool
    {
        if (!$this->hasExtension('pg_trgm')) {
            return false;
        }

        try {
            $db = SmartSearch::getInstance()->databaseService->getConnection();
            $table = $this->qualifiedTermsTable();
            $stmt = $db->query("SELECT EXISTS (SELECT 1 FROM {$table} LIMIT 1)");
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Idempotent best-effort schema setup. Each statement is wrapped so a
     * permission failure on one (e.g. CREATE EXTENSION) does not prevent the
     * others from running. Returns true if the dictionary table exists at the
     * end (the minimum for rebuild to populate something queryable).
     */
    public function ensureSchema(): bool
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();
        $schema = SmartSearch::getInstance()->getSettings()->vectorsSchemaName;
        $table = $this->qualifiedTermsTable();

        $indexName = $this->configuredTermsTableName() . '_trgm_idx';
        $statements = [
            'pg_trgm extension' => 'CREATE EXTENSION IF NOT EXISTS pg_trgm',
            'fuzzystrmatch extension' => 'CREATE EXTENSION IF NOT EXISTS fuzzystrmatch',
            'terms table' => "CREATE TABLE IF NOT EXISTS {$table} (term text PRIMARY KEY, df integer NOT NULL DEFAULT 0)",
            'terms trgm index' => "CREATE INDEX IF NOT EXISTS \"{$indexName}\" ON {$table} USING GIN (term gin_trgm_ops)",
        ];

        $tableExists = false;
        foreach ($statements as $label => $sql) {
            try {
                $db->exec($sql);
                if ($label === 'terms table') {
                    $tableExists = true;
                }
            } catch (PDOException $e) {
                Logger::warning("Typo-tolerance schema step skipped: {$label}", [
                    'error' => $e->getMessage(),
                    'hint' => 'Run the SQL manually as a superuser if you want typo correction enabled.',
                ]);
            }
        }

        if (!$tableExists) {
            try {
                $stmt = $db->prepare(
                    "SELECT 1 FROM pg_tables WHERE schemaname = :schema AND tablename = :table"
                );
                $stmt->execute([':schema' => $schema, ':table' => $this->configuredTermsTableName()]);
                $tableExists = (bool)$stmt->fetchColumn();
            } catch (PDOException) {
                $tableExists = false;
            }
        }

        $this->clearAvailabilityCache();
        return $tableExists;
    }

    /**
     * Rebuild the dictionary from the corpus tsvectors. Idempotent — fully
     * replaces existing rows in one transaction. No-ops gracefully when the
     * terms table does not exist.
     *
     * Returns the number of rows written, or null if the rebuild was skipped.
     */
    public function rebuild(?string $tsConfig = 'simple'): ?int
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();
        $vectors = SmartSearch::getInstance()->databaseService->getQualifiedTable();
        $terms = $this->qualifiedTermsTable();
        $config = $this->sanitizeTsConfig($tsConfig);

        try {
            $exists = $db->prepare(
                "SELECT 1 FROM pg_tables WHERE schemaname = :schema AND tablename = :table"
            );
            $exists->execute([
                ':schema' => SmartSearch::getInstance()->getSettings()->vectorsSchemaName,
                ':table' => $this->configuredTermsTableName(),
            ]);
            if (!$exists->fetchColumn()) {
                Logger::warning('Dictionary rebuild skipped — terms table does not exist', [
                    'table' => $terms,
                ]);
                return null;
            }

            $db->beginTransaction();
            $db->exec("TRUNCATE {$terms}");

            // ts_stat samples the lexemes already stored in the indexed content.
            // The nword (frequency) column doubles as a tie-breaker when ranking
            // similar candidates: prefer common words over rare ones.
            $sql = "
                INSERT INTO {$terms} (term, df)
                SELECT word, nentry
                FROM ts_stat(\$\$SELECT to_tsvector('{$config}', COALESCE(content, '')) FROM {$vectors}\$\$)
                WHERE char_length(word) >= 3
                ON CONFLICT (term) DO UPDATE SET df = EXCLUDED.df
            ";
            $written = $db->exec($sql);
            $db->commit();

            $this->clearAvailabilityCache();

            Logger::info('Dictionary rebuilt', [
                'rows' => $written,
                'tsConfig' => $config,
            ]);

            return $written ?: 0;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Logger::exception($e, 'DictionaryService::rebuild');
            return null;
        }
    }

    public function qualifiedTermsTable(): string
    {
        $schema = SmartSearch::getInstance()->getSettings()->vectorsSchemaName;
        return "\"{$schema}\".\"{$this->configuredTermsTableName()}\"";
    }

    private function configuredTermsTableName(): string
    {
        $name = SmartSearch::getInstance()->getSettings()->termsTableName;
        return $name !== '' ? $name : self::TERMS_TABLE;
    }

    private function clearAvailabilityCache(): void
    {
        $this->availabilityCache = null;
        Craft::$app->getCache()->delete('smart_search_typo_available');
    }

    /**
     * Reuse KeywordSearchService's whitelist semantics — anything unknown collapses to
     * 'simple', which never throws and avoids SQL injection via the inlined
     * config name.
     */
    private function sanitizeTsConfig(?string $config): string
    {
        $supported = [
            'simple', 'arabic', 'armenian', 'basque', 'catalan', 'danish', 'dutch',
            'english', 'finnish', 'french', 'german', 'greek', 'hindi', 'hungarian',
            'indonesian', 'irish', 'italian', 'lithuanian', 'nepali', 'norwegian',
            'portuguese', 'romanian', 'russian', 'serbian', 'spanish', 'swedish',
            'tamil', 'turkish', 'yiddish',
        ];
        $config = strtolower((string)$config);
        return in_array($config, $supported, true) ? $config : 'simple';
    }
}
