<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\SqlHelper;
use ghoststreet\craftsmartsearch\SmartSearch;
use PDO;
use PDOException;
use Throwable;
use yii\base\Component;

/**
 * Owns the per-entry boost table in the pgvector store. At index time a
 * developer's EVENT_INDEX_BOOSTS listener attaches weighted phrase rules to an
 * entry; at search time any entry whose rule phrases appear in the (typo-
 * corrected) query gets its weight added to the rank.
 *
 * Rules are stored as phrase-accurate `tsquery` values (phrases AND-combined),
 * so "2 bedroom 1 bathroom" fires the "2 bedroom" and "1 bathroom" rules but
 * not "1 bedroom". Matching is a siteId-filtered scan evaluating `@@ rule_query`
 * against the query tsvector.
 *
 * Like the rest of the plugin's pgvector access, it never throws on a missing
 * table: the feature is inert until the install SQL (or ensureSchema) provisions
 * it.
 *
 * ponytail: siteId-filtered seq scan over rules; fine for thousands of rules,
 * add a GIN lexeme pre-filter column if a site ever has tens of thousands.
 */
class BoostService extends Component
{
    private const TABLE_EXISTS_CACHE_KEY = 'smart_search_boosts_table_exists';

    private const TABLE_EXISTS_CACHE_TTL = 60;

    private ?bool $tableExistsCache = null;

    /**
     * Best-effort idempotent provisioning, mirroring DictionaryService::ensureSchema().
     * Swallows permission failures — a locked-down role relies on the install SQL
     * instead and syncEntry() then no-ops gracefully.
     */
    private function ensureSchema(): void
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();
        $table = $this->qualifiedBoostsTable();
        $name = SmartSearch::getInstance()->getSettings()->boostsTableName;

        $statements = [
            'boosts table' => "CREATE TABLE IF NOT EXISTS {$table} (
                id          bigserial PRIMARY KEY,
                \"elementId\" integer NOT NULL,
                \"siteId\"    integer NOT NULL,
                label       text NOT NULL,
                rule_query  tsquery NOT NULL,
                weight      real NOT NULL DEFAULT 0
            )",
            'boosts element index' => "CREATE INDEX IF NOT EXISTS \"{$name}_el_idx\" ON {$table} (\"elementId\", \"siteId\")",
            'boosts site index' => "CREATE INDEX IF NOT EXISTS \"{$name}_site_idx\" ON {$table} (\"siteId\")",
        ];

        foreach ($statements as $label => $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                Logger::warning("Boost schema step skipped: {$label}", ['error' => $e->getMessage()]);
            }
        }

        $this->tableExistsCache = null;
        Craft::$app->getCache()->delete(self::TABLE_EXISTS_CACHE_KEY);
    }

    /**
     * Replace an entry's boost rules. Each rule becomes one row whose rule_query
     * ANDs a phraseto_tsquery per phrase, so all phrases must appear in the query
     * for the weight to apply. Rules with empty terms or non-positive weight are
     * skipped. Best-effort: a missing table is provisioned first, and any failure
     * is logged rather than thrown so indexing never fails on a boost problem.
     *
     * $rules comes from an untrusted listener, so each entry is read defensively.
     *
     * @param array<array<string, mixed>> $rules
     */
    public function syncEntry(int $elementId, int $siteId, array $rules): void
    {
        try {
            if (!$this->tableExists()) {
                $this->ensureSchema();
            }

            $db = SmartSearch::getInstance()->databaseService->getConnection();
            $table = $this->qualifiedBoostsTable();
            $language = KeywordSearchService::resolveLanguage($siteId);

            $db->prepare("DELETE FROM {$table} WHERE \"elementId\" = :e AND \"siteId\" = :s")
                ->execute([':e' => $elementId, ':s' => $siteId]);

            foreach ($rules as $rule) {
                $termsRaw = $rule['terms'] ?? [];
                if (!is_array($termsRaw)) {
                    continue;
                }
                $terms = array_values(array_filter(
                    array_map(static fn(mixed $t): string => trim((string)$t), $termsRaw),
                    static fn(string $t): bool => $t !== '',
                ));
                $weight = (float)($rule['weight'] ?? 0);

                if ($terms === [] || $weight <= 0) {
                    continue;
                }

                $queryParts = [];
                $params = [
                    ':e' => $elementId,
                    ':s' => $siteId,
                    ':label' => implode(' + ', $terms),
                    ':w' => $weight,
                ];
                foreach ($terms as $i => $phrase) {
                    $queryParts[] = "phraseto_tsquery('{$language}', :p{$i})";
                    $params[":p{$i}"] = $phrase;
                }
                $ruleQuery = implode(' && ', $queryParts);

                $db->prepare(
                    "INSERT INTO {$table} (\"elementId\", \"siteId\", label, rule_query, weight)
                     VALUES (:e, :s, :label, ({$ruleQuery}), :w)"
                )->execute($params);
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'BoostService::syncEntry', ['elementId' => $elementId, 'siteId' => $siteId]);
        }
    }

    public function deleteForEntry(int $elementId, ?int $siteId = null): void
    {
        try {
            if (!$this->tableExists()) {
                return;
            }

            $db = SmartSearch::getInstance()->databaseService->getConnection();
            $table = $this->qualifiedBoostsTable();

            if ($siteId !== null) {
                $db->prepare("DELETE FROM {$table} WHERE \"elementId\" = :e AND \"siteId\" = :s")
                    ->execute([':e' => $elementId, ':s' => $siteId]);
            } else {
                $db->prepare("DELETE FROM {$table} WHERE \"elementId\" = :e")
                    ->execute([':e' => $elementId]);
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'BoostService::deleteForEntry', ['elementId' => $elementId]);
        }
    }

    /**
     * Weight to add per element for the given query. Returns [] when the table is
     * unprovisioned or nothing matches, so the feature is inert until set up.
     *
     * @return array<int, float> elementId => summed weight
     */
    public function match(string $query, ?int $siteId): array
    {
        $built = $this->buildMatchQuery($query, $siteId);
        if ($built === null) {
            return [];
        }
        [$sql, $params] = $built;

        try {
            $out = [];
            foreach (SmartSearch::getInstance()->databaseService->fetchAll($sql, $params, 'BoostService::match') as $row) {
                $out[(int)$row['elementId']] = (float)$row['w'];
            }
            return $out;
        } catch (Throwable $e) {
            Logger::exception($e, 'BoostService::match', ['siteId' => $siteId]);
            return [];
        }
    }

    /**
     * Dispatch the boost match without waiting, returning a callable that collects
     * it later. Null when no async handle is free, so the caller falls back to match().
     *
     * Depends only on the query text, so it may be sent before the vector query and
     * collected after it.
     *
     * @return null|callable(): array<int, float>
     */
    public function prefetchMatch(string $query, ?int $siteId): ?callable
    {
        $connection = SmartSearch::getInstance()->databaseService->getAsyncConnection();
        if ($connection === null) {
            return null;
        }

        $built = $this->buildMatchQuery($query, $siteId);
        if ($built === null) {
            return static fn(): array => [];
        }

        [$sql, $params] = $built;
        [$pgSql, $values] = SqlHelper::toPositional($sql, $params);

        if (@pg_send_query_params($connection, $pgSql, $values) === false) {
            return null;
        }

        return static function() use ($connection, $siteId): array {
            $out = [];
            $error = null;

            while (($result = pg_get_result($connection)) !== false) {
                if (pg_result_error($result) !== '') {
                    $error = pg_result_error($result);
                    continue;
                }
                foreach (pg_fetch_all($result) ?: [] as $row) {
                    $out[(int)$row['elementId']] = (float)$row['w'];
                }
            }

            /* Boosts only ever add rank, so a failure degrades rather than throws. */
            if ($error !== null) {
                Logger::warning('Boost prefetch failed; ranking without boosts', [
                    'error' => $error,
                    'siteId' => $siteId,
                ]);
                return [];
            }

            return $out;
        };
    }

    /**
     * Build the boost match SQL and params, or null when boosts cannot apply.
     * Shared by the sync and prefetch paths so they cannot drift apart.
     *
     * @return array{0: string, 1: array<string, scalar|null>}|null
     */
    private function buildMatchQuery(string $query, ?int $siteId): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        try {
            $tsv = SmartSearch::getInstance()->queryCorrectorService->expandedTsvector($query, $siteId);
            if ($tsv === '') {
                return null;
            }

            $table = $this->qualifiedBoostsTable();

            $where = 'q.tsv @@ b.rule_query';
            $params = [':tsv' => $tsv];
            if ($siteId !== null) {
                $where = 'b."siteId" = :siteId AND ' . $where;
                $params[':siteId'] = $siteId;
            }

            return [
                "WITH q AS (SELECT (:tsv)::tsvector AS tsv)
                 SELECT b.\"elementId\" AS \"elementId\", SUM(b.weight) AS w
                 FROM {$table} b, q
                 WHERE {$where}
                 GROUP BY b.\"elementId\"",
                $params,
            ];
        } catch (Throwable $e) {
            Logger::exception($e, 'BoostService::buildMatchQuery', ['siteId' => $siteId]);
            return null;
        }
    }

    /**
     * Human-readable rules for one entry, for the CP inspection page.
     *
     * @return array<array{label: string, weight: float}>
     */
    public function getRulesForElement(int $elementId, int $siteId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        try {
            $db = SmartSearch::getInstance()->databaseService->getConnection();
            $table = $this->qualifiedBoostsTable();
            $stmt = $db->prepare(
                "SELECT label, weight FROM {$table}
                 WHERE \"elementId\" = :e AND \"siteId\" = :s
                 ORDER BY weight DESC"
            );
            $stmt->execute([':e' => $elementId, ':s' => $siteId]);

            $rows = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = ['label' => (string)$row['label'], 'weight' => (float)$row['weight']];
            }
            return $rows;
        } catch (Throwable $e) {
            Logger::exception($e, 'BoostService::getRulesForElement', ['elementId' => $elementId]);
            return [];
        }
    }

    /**
     * The answer only changes when the table is provisioned, so it is cached;
     * ensureSchema() clears the key so provisioning takes effect immediately.
     */
    private function tableExists(): bool
    {
        if ($this->tableExistsCache !== null) {
            return $this->tableExistsCache;
        }

        return $this->tableExistsCache = (bool)Craft::$app->getCache()->getOrSet(
            self::TABLE_EXISTS_CACHE_KEY,
            fn() => $this->queryTableExists() ? 1 : 0,
            self::TABLE_EXISTS_CACHE_TTL,
        );
    }

    private function queryTableExists(): bool
    {
        try {
            $db = SmartSearch::getInstance()->databaseService->getConnection();
            $schema = SmartSearch::getInstance()->getSettings()->vectorsSchemaName;
            $stmt = $db->prepare("SELECT 1 FROM pg_tables WHERE schemaname = :schema AND tablename = :table");
            $stmt->execute([':schema' => $schema, ':table' => SmartSearch::getInstance()->getSettings()->boostsTableName]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function qualifiedBoostsTable(): string
    {
        $settings = SmartSearch::getInstance()->getSettings();
        return "\"{$settings->vectorsSchemaName}\".\"{$settings->boostsTableName}\"";
    }
}
