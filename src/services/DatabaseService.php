<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\exceptions\DatabaseException;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;
use PDO;
use PDOException;
use PDOStatement;
use yii\base\Component;

/**
 * Database Service for connecting to the pgvector-backed PostgreSQL database.
 *
 * Handles connection management (including URI parsing) and CRUD/query helpers
 * on the admin-managed vectors table. The plugin does NOT create or modify the
 * schema — the admin runs the SQL from the README before configuring this
 * service.
 */
class DatabaseService extends Component
{
    public const STATS_CACHE_KEY = 'smart_search_dashboard_stats';

    private const LOCAL_HOSTS = ['127.0.0.1', '::1', 'localhost'];

    private ?PDO $connection = null;

    /**
     * Get database connection, throwing an exception if not configured or connection fails.
     *
     * @throws DatabaseException If configuration is incomplete, SSL policy fails, or connection fails
     */
    public function getConnection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $this->connection = $this->buildConnection($this->resolveConnectionConfig());

        return $this->connection;
    }

    /**
     * Build a one-off PDO connection from an explicit config array.
     *
     * Used by the settings "Test connection" action so admins can validate DB
     * credentials before saving — bypasses the cached connection that reads
     * from saved settings. Does not mutate $this->connection.
     *
     * @param array{host: ?string, port: int|string, database: ?string, user: ?string, password: ?string, sslMode: string} $config
     * @throws DatabaseException
     */
    public function connectWithConfig(array $config): PDO
    {
        return $this->buildConnection($config);
    }

    /**
     * Apply the same host-as-connection-URI expansion that resolveConnectionConfig() does,
     * so the settings "Test connection" action behaves identically to the saved path.
     */
    public function expandPostedConfig(array $config): array
    {
        $host = (string)($config['host'] ?? '');
        if ($host !== '' && $this->isConnectionUri($host)) {
            $parsed = $this->parseConnectionUri($host);
            if ($parsed) {
                return [...$parsed, 'sslMode' => $config['sslMode'] ?? 'require'];
            }
        }
        return $config;
    }

    private function buildConnection(array $config): PDO
    {
        $missingFields = array_values(array_filter(
            ['host', 'database', 'user', 'password'],
            static fn(string $field) => empty($config[$field])
        ));

        if (!empty($missingFields)) {
            throw DatabaseException::configurationIncomplete($missingFields);
        }

        $this->enforceSslPolicy($config);

        $dsn = $this->buildDsn($config);

        return $this->createConnection($dsn, $config['user'], $config['password']);
    }

    /**
     * Return the validated, fully-qualified vectors table identifier (`"schema"."table"`).
     */
    public function getQualifiedTable(): string
    {
        return SmartSearch::getInstance()->getSettings()->getQualifiedVectorsTable();
    }

    /**
     * Reject `disable` / `allow` / `prefer` SSL modes for non-localhost hosts.
     *
     * @throws DatabaseException
     */
    private function enforceSslPolicy(array $config): void
    {
        $host = (string)($config['host'] ?? '');
        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return;
        }

        $weak = ['disable', 'allow', 'prefer'];
        if (in_array($config['sslMode'], $weak, true)) {
            throw DatabaseException::connectionError(
                "Refusing to connect to remote host '{$host}' with sslmode='{$config['sslMode']}'. " .
                "Use 'require', 'verify-ca', or 'verify-full'."
            );
        }
    }

    /**
     * Resolve connection configuration from plugin settings, parsing a connection URI
     * if the host field contains one, otherwise using individual field values.
     *
     * @return array{host: ?string, port: int|string, database: ?string, user: ?string, password: ?string, sslMode: string}
     */
    private function resolveConnectionConfig(): array
    {
        $settings = SmartSearch::getInstance()->getSettings();

        $host = $settings->getPostgresqlHost();
        $port = $settings->getPostgresqlPort();
        $database = $settings->getPostgresqlDatabase();
        $user = $settings->getPostgresqlUser();
        $password = $settings->getPostgresqlPassword();
        $sslMode = $settings->getPostgresqlSslMode();

        if (!empty($host) && $this->isConnectionUri($host)) {
            $parsedConnectionUri = $this->parseConnectionUri($host);

            if ($parsedConnectionUri) {
                return [...$parsedConnectionUri, 'sslMode' => $sslMode];
            }

            Logger::warning('Failed to parse connection URI');
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'user' => $user,
            'password' => $password,
            'sslMode' => $sslMode,
        ];
    }

    /**
     * Check if a host string is a full PostgreSQL connection URI.
     */
    private function isConnectionUri(string $host): bool
    {
        return in_array(parse_url($host, PHP_URL_SCHEME), ['postgres', 'postgresql'], true);
    }

    /**
     * Build a PDO DSN string from the resolved connection config, preferring
     * Always uses `host` (hostname), never `hostaddr`. A hostname lets libpq try
     * every resolved address in turn; a pre-resolved `hostaddr` pins us to one
     * address with no failover. Managed pooler endpoints sit behind a load
     * balancer with several rotating A records, so pinning one turns a single
     * unhealthy node into a hard failure.
     */
    private function buildDsn(array $config): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['sslMode']
        );
    }

    /**
     * Deliberately NOT persistent. Against a transaction-mode pooler the pooler
     * is the connection pool; a persistent handle outlives the server side that
     * backs it, and PDO hands the dead socket back without a round-trip, so the
     * failure surfaces on the next query as an opaque SSL eof.
     *
     * Session `SET` is deliberately absent for the same reason: a pooled backend
     * is shared, so a SET here leaks into other clients' sessions and is missing
     * from ours at random. Per-query GUCs are applied with SET LOCAL inside the
     * querying transaction instead — see SmartSearchService::semanticSearchRaw().
     *
     * @throws DatabaseException If connection fails
     */
    private function createConnection(string $dsn, string $user, string $password): PDO
    {
        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            Logger::info('Successfully connected to PostgreSQL database');

            return $pdo;
        } catch (PDOException $e) {
            Logger::exception($e, 'getConnection');
            throw DatabaseException::connectionError($e->getMessage(), $e);
        }
    }

    /**
     * Verify that the configured vectors table exists. The plugin never issues
     * DDL — admin owns the schema and must run the README SQL before this call.
     *
     * @throws DatabaseException If the table is missing or the lookup fails
     */
    public function preflightSchema(): void
    {
        $settings = SmartSearch::getInstance()->getSettings();
        $schema = $settings->vectorsSchemaName;
        $table = $settings->vectorsTableName;

        try {
            $db = $this->getConnection();
            $stmt = $db->prepare('SELECT 1 FROM pg_tables WHERE schemaname = :schema AND tablename = :table');
            $stmt->execute([':schema' => $schema, ':table' => $table]);

            if ($stmt->fetch() === false) {
                throw DatabaseException::connectionError(sprintf(
                    'Vectors table "%s"."%s" does not exist. Run the schema SQL from the plugin README to create it.',
                    $schema,
                    $table
                ));
            }
        } catch (PDOException $e) {
            Logger::exception($e, 'preflightSchema');
            throw DatabaseException::connectionError($e->getMessage(), $e);
        }
    }

    /**
     * Non-throwing wrapper around preflightSchema() for CP dashboards that need
     * to render even when the vectors table is missing or the DB is unreachable.
     */
    public function isSchemaInitialized(): bool
    {
        try {
            $this->preflightSchema();
            return true;
        } catch (DatabaseException) {
            return false;
        }
    }

    /**
     * Return the stored content hash and chunk count for an entry.
     *
     * The hash is written identically to every chunk row of an entry, so
     * reading the MAX is sufficient. The chunk count lets callers detect
     * partial-index states (hash matches but rows are missing).
     *
     * @return array{hash: ?string, chunkCount: int}
     * @throws DatabaseException
     */
    public function getStoredEntryFingerprint(int $elementId, int $siteId): array
    {
        $table = $this->getQualifiedTable();
        $stmt = $this->executeStatement(
            "SELECT MAX(\"contentHash\") AS \"contentHash\", COUNT(*) AS \"chunkCount\"
             FROM {$table}
             WHERE \"elementId\" = :elementId AND \"siteId\" = :siteId",
            [':elementId' => $elementId, ':siteId' => $siteId],
            'getStoredEntryFingerprint'
        );
        $row = $stmt->fetch();

        return [
            'hash' => $row['contentHash'] ?? null,
            'chunkCount' => (int)($row['chunkCount'] ?? 0),
        ];
    }

    /**
     * Prepare + execute with consistent error handling. PDOException is logged
     * and rethrown as DatabaseException::queryFailed (with the qualified vector
     * table for the missing-table case). Use this for every query in this
     * service except multi-step transactions.
     *
     * @throws DatabaseException
     */
    public function executeStatement(string $sql, array $params, string $operation): PDOStatement
    {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            Logger::exception($e, $operation);
            throw DatabaseException::queryFailed($operation, $e, $this->getQualifiedTable());
        }
    }

    /**
     * Delete vectors for entries no longer present in the supplied active set.
     *
     * @param list<array{elementId: int, siteId: int}> $activeKeys
     * @return int Number of deleted rows
     * @throws DatabaseException
     */
    public function deleteOrphanedVectors(array $activeKeys, ?int $siteId = null): int
    {
        $db = $this->getConnection();
        $table = $this->getQualifiedTable();

        try {
            if ($siteId !== null) {
                $stmt = $db->prepare("SELECT DISTINCT \"elementId\", \"siteId\" FROM {$table} WHERE \"siteId\" = :siteId");
                $stmt->execute([':siteId' => $siteId]);
            } else {
                $stmt = $db->query("SELECT DISTINCT \"elementId\", \"siteId\" FROM {$table}");
            }
            $indexed = $stmt->fetchAll();

            $activeLookup = array_flip(array_map(
                static fn(array $key) => "{$key['elementId']}:{$key['siteId']}",
                $activeKeys
            ));

            $orphans = array_values(array_filter(
                array_map(
                    static fn(array $row) => [
                        'elementId' => (int)$row['elementId'],
                        'siteId' => (int)$row['siteId'],
                    ],
                    $indexed
                ),
                static fn(array $key) => !isset($activeLookup["{$key['elementId']}:{$key['siteId']}"])
            ));

            if (empty($orphans)) {
                return 0;
            }

            $deleted = 0;
            foreach (array_chunk($orphans, 500) as $batch) {
                $placeholders = [];
                $params = [];
                foreach ($batch as $i => $key) {
                    $placeholders[] = "(:e{$i}, :s{$i})";
                    $params[":e{$i}"] = $key['elementId'];
                    $params[":s{$i}"] = $key['siteId'];
                }
                $sql = "DELETE FROM {$table} WHERE (\"elementId\", \"siteId\") IN (" . implode(', ', $placeholders) . ')';
                $del = $db->prepare($sql);
                $del->execute($params);
                $deleted += $del->rowCount();
            }

            Logger::info('Deleted orphaned vectors', ['entries' => count($orphans), 'rows' => $deleted]);
            return $deleted;
        } catch (PDOException $e) {
            Logger::exception($e, 'deleteOrphanedVectors');
            throw DatabaseException::queryFailed('deleteOrphanedVectors', $e);
        }
    }

    /**
     * Delete all vectors while preserving the table structure and indexes.
     *
     * @return int Number of deleted rows
     * @throws DatabaseException If connection fails or query fails
     */
    public function clearAllVectors(): int
    {
        $stmt = $this->executeStatement("DELETE FROM {$this->getQualifiedTable()}", [], 'clearAllVectors');
        $count = $stmt->rowCount();
        Logger::info('Cleared all vectors', ['count' => $count]);
        return $count;
    }

    /**
     * Fetch dashboard statistics (entry count, chunk count, last indexed date) with connection status.
     *
     * @return array<string, array{chunkCount: int, lastIndexed: string}>
     * @throws DatabaseException
     */
    public function getIndexedSummary(?int $siteId = null): array
    {
        $table = $this->getQualifiedTable();
        $sql = "SELECT \"elementId\", \"siteId\", COUNT(*) AS \"chunkCount\", MAX(\"dateUpdated\") AS \"lastIndexed\"
                FROM {$table}";
        $params = [];
        if ($siteId !== null) {
            $sql .= ' WHERE "siteId" = :siteId';
            $params[':siteId'] = $siteId;
        }
        $sql .= ' GROUP BY "elementId", "siteId"';

        $stmt = $this->executeStatement($sql, $params, 'getIndexedSummary');

        $map = [];
        while ($row = $stmt->fetch()) {
            $key = $row['elementId'] . '-' . $row['siteId'];
            $map[$key] = [
                'chunkCount' => (int)$row['chunkCount'],
                'lastIndexed' => $row['lastIndexed'],
            ];
        }
        return $map;
    }

    /**
     * @throws DatabaseException
     */
    public function getVectorsForElement(int $elementId, int $siteId): array
    {
        $table = $this->getQualifiedTable();
        return $this->executeStatement(
            "SELECT \"chunkIndex\", \"totalChunks\", body AS content, \"dateUpdated\"
             FROM {$table}
             WHERE \"elementId\" = :elementId AND \"siteId\" = :siteId
             ORDER BY \"chunkIndex\" ASC",
            [':elementId' => $elementId, ':siteId' => $siteId],
            'getVectorsForElement'
        )->fetchAll();
    }

    public function getStats(bool $useCache = true): array
    {
        $cache = \Craft::$app->getCache();

        if (!$useCache) {
            $cache->delete(self::STATS_CACHE_KEY);
        }

        return $cache->getOrSet(self::STATS_CACHE_KEY, function() {
            $table = $this->getQualifiedTable();
            $row = $this->executeStatement(
                "SELECT COUNT(DISTINCT \"elementId\") AS \"entryCount\",
                        COUNT(*) AS \"chunkCount\",
                        MAX(\"dateUpdated\") AS \"lastIndexed\"
                 FROM {$table}",
                [],
                'getStats'
            )->fetch();

            return [
                'entryCount' => (int)($row['entryCount'] ?? 0),
                'chunkCount' => (int)($row['chunkCount'] ?? 0),
                'lastIndexed' => $row['lastIndexed'] ?? null,
                'isConnected' => true,
                'error' => null,
            ];
        }, 60);
    }

    /**
     * Per-site dashboard counters from the vectors table.
     *
     * @return list<array{siteId: int, entryCount: int, chunkCount: int, lastIndexed: ?string}>
     * @throws DatabaseException
     */
    public function getStatsPerSite(): array
    {
        $table = $this->getQualifiedTable();
        $stmt = $this->executeStatement(
            "SELECT \"siteId\",
                    COUNT(DISTINCT \"elementId\") AS \"entryCount\",
                    COUNT(*) AS \"chunkCount\",
                    MAX(\"dateUpdated\") AS \"lastIndexed\"
             FROM {$table}
             GROUP BY \"siteId\"",
            [],
            'getStatsPerSite'
        );

        $rows = [];
        while ($row = $stmt->fetch()) {
            $rows[] = [
                'siteId' => (int)$row['siteId'],
                'entryCount' => (int)$row['entryCount'],
                'chunkCount' => (int)$row['chunkCount'],
                'lastIndexed' => $row['lastIndexed'] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * Like getStats(), but returns a canonical disconnected-shape on failure
     * instead of throwing. Use from CP pages that need to render even when
     * the vector DB is unreachable.
     */
    public function getStatsSafe(): array
    {
        try {
            return $this->getStats();
        } catch (\Throwable $e) {
            return [
                'entryCount' => 0,
                'chunkCount' => 0,
                'lastIndexed' => null,
                'isConnected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse a PostgreSQL connection URI (postgresql://user:pass@host:port/db) into
     * its component parts. Returns null if the URI does not match the expected format.
     *
     * @return array{user: string, password: string, host: string, port: int, database: string}|null
     */
    private function parseConnectionUri(string $uri): ?array
    {
        $parts = parse_url($uri) ?: [];
        $database = ltrim($parts['path'] ?? '', '/');

        if (!isset($parts['user'], $parts['pass'], $parts['host']) || $database === '') {
            Logger::warning('Failed to parse PostgreSQL connection URI', [
                'expected' => 'postgresql://user:password@host:port/database',
                'received' => preg_replace('/\/\/[^@]*@/', '//[redacted]@', $uri),
            ]);

            return null;
        }

        return [
            'user' => urldecode($parts['user']),
            'password' => urldecode($parts['pass']),
            'host' => trim($parts['host'], '[]'),
            'port' => (int)($parts['port'] ?? 5432),
            'database' => $database,
        ];
    }
}
