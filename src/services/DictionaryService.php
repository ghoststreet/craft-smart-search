<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use ghoststreet\craftsmartsearch\helpers\Logger;
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

    private const AVAILABILITY_CACHE_KEY = 'smart_search_typo_available';

    private const EXTENSION_CACHE_KEY_PREFIX = 'smart_search_pg_extension_';

    private static array $extensionCache = [];

    /**
     * Add this entry's lexemes to the terms table without scanning the whole corpus.
     * df is incremented per term on conflict.
     */
    public function syncEntry(int $elementId, int $siteId): void
    {
        try {
            if (!SmartSearch::getInstance()->getSettings()->enableTypoTolerance) {
                return;
            }

            $db = SmartSearch::getInstance()->databaseService->getConnection();
            $vectors = SmartSearch::getInstance()->databaseService->getQualifiedTable();
            $terms = $this->qualifiedTermsTable();
            $elementId = (int)$elementId;
            $siteId = (int)$siteId;

            $innerSql = "SELECT tsv FROM {$vectors} WHERE \"elementId\" = {$elementId} AND \"siteId\" = {$siteId} AND tsv IS NOT NULL";
            $sql = "
                INSERT INTO {$terms} (term, df)
                SELECT word, nentry
                FROM ts_stat(\$\${$innerSql}\$\$)
                WHERE char_length(word) >= 3
                ON CONFLICT (term) DO UPDATE SET df = {$terms}.df + EXCLUDED.df
            ";

            $rows = (int)$db->exec($sql);

            Logger::info('syncEntry', [
                'entryId' => $elementId,
                'siteId' => $siteId,
                'lexemesUpserted' => $rows,
            ]);

            $this->clearAvailabilityCache();
        } catch (Throwable $e) {
            Logger::exception($e, 'DictionaryService::syncEntry');
        }
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

    /**
     * True only when pg_trgm is installed and the dictionary table has rows.
     * Cached for 60s to avoid hammering pg_extension.
     */
    public function isAvailable(): bool
    {
        return (bool)Craft::$app->getCache()->getOrSet(
            self::AVAILABILITY_CACHE_KEY,
            fn() => $this->checkAvailability() ? 1 : 0,
            self::REQUEST_CACHE_TTL_SECONDS
        );
    }

    /** Cacheable because an installed extension only changes when an admin runs DDL. */
    public function hasExtension(string $name): bool
    {
        if (isset(self::$extensionCache[$name])) {
            return self::$extensionCache[$name];
        }

        return self::$extensionCache[$name] = (bool)Craft::$app->getCache()->getOrSet(
            self::EXTENSION_CACHE_KEY_PREFIX . $name,
            fn() => $this->queryHasExtension($name) ? 1 : 0,
            self::REQUEST_CACHE_TTL_SECONDS
        );
    }

    private function queryHasExtension(string $name): bool
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
    public function rebuild(): ?int
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();
        $vectors = SmartSearch::getInstance()->databaseService->getQualifiedTable();
        $terms = $this->qualifiedTermsTable();

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

            $written = $db->exec("
                INSERT INTO {$terms} (term, df)
                SELECT word, nentry
                FROM ts_stat(\$\$SELECT tsv FROM {$vectors} WHERE tsv IS NOT NULL\$\$)
                WHERE char_length(word) >= 3
            ");
            $db->commit();

            $this->clearAvailabilityCache();

            Logger::info('Dictionary rebuilt', ['rows' => (int)$written]);

            return (int)$written;
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
        Craft::$app->getCache()->delete(self::AVAILABILITY_CACHE_KEY);
    }
}
