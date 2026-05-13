<?php

namespace ghoststreet\craftaisearch\services;

use Craft;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\exceptions\DatabaseException;
use ghoststreet\craftaisearch\helpers\Logger;
use PDO;
use PDOException;
use yii\base\Component;

/**
 * Database Service for managing PostgreSQL vector database with pgvector.
 *
 * Handles connection management (including URI parsing and IPv4 resolution
 * for cloud providers), schema initialization with pgvector indexes, and
 * CRUD operations on the vectors table.
 */
class DatabaseService extends Component
{
    public const TABLE_NAME = 'aisearch_vectors';

    /** Cache key used to skip repeated schema initialization checks */
    public const SCHEMA_CACHE_KEY = 'aisearch_schema_initialized';

    private ?PDO $connection = null;

    /**
     * Get database connection, throwing an exception if not configured or connection fails.
     *
     * @throws DatabaseException If configuration is incomplete or connection fails
     */
    public function getConnection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $config = $this->resolveConnectionConfig();
        $missingFields = $this->getMissingConfigFields($config);

        if (!empty($missingFields)) {
            throw DatabaseException::configurationIncomplete($missingFields);
        }

        $dsn = $this->buildDsn($config);

        return $this->createConnection($dsn, $config['user'], $config['password']);
    }

    /**
     * Resolve connection configuration from plugin settings, parsing a connection URI
     * if the host field contains one, otherwise using individual field values.
     *
     * @return array{host: ?string, port: int|string, database: ?string, user: ?string, password: ?string, sslMode: string}
     */
    private function resolveConnectionConfig(): array
    {
        $settings = AiSearch::getInstance()->getSettings();

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
     * Get list of missing required configuration fields.
     *
     * @return string[] Missing field names, empty if config is complete
     */
    private function getMissingConfigFields(array $config): array
    {
        $missing = [];

        foreach (['host', 'database', 'user', 'password'] as $field) {
            if (empty($config[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Check if a host string is a full PostgreSQL connection URI.
     */
    private function isConnectionUri(string $host): bool
    {
        return str_starts_with($host, 'postgresql://') || str_starts_with($host, 'postgres://');
    }

    /**
     * Build a PDO DSN string from the resolved connection config, preferring
     * `hostaddr` (IPv4) over `host` (hostname) for cloud provider compatibility.
     */
    private function buildDsn(array $config): string
    {
        $hostaddr = $this->resolveHostAddress($config['host']);

        if ($hostaddr) {
            return sprintf(
                'pgsql:hostaddr=%s;port=%d;dbname=%s;sslmode=%s',
                $hostaddr,
                $config['port'],
                $config['database'],
                $config['sslMode']
            );
        }

        Logger::warning('Could not resolve IPv4 address, using hostname', ['host' => $config['host']]);

        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['sslMode']
        );
    }

    /**
     * Resolve hostname to IPv4 for the PDO DSN `hostaddr` parameter.
     *
     * Some cloud PostgreSQL providers (e.g. Neon, Supabase) require IPv4
     * addresses instead of hostnames due to libpq SNI/SSL handshake issues.
     * Returns null if resolution fails, causing buildDsn() to fall back to hostname.
     */
    private function resolveHostAddress(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        $records = dns_get_record($host, DNS_A);

        if (!empty($records) && isset($records[0]['ip']) &&
            filter_var($records[0]['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $records[0]['ip'];
        }

        $resolved = gethostbyname($host);

        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $resolved;
        }

        return null;
    }

    /**
     * @throws DatabaseException If connection fails
     */
    private function createConnection(string $dsn, string $user, string $password): PDO
    {
        try {
            $this->connection = new PDO($dsn, $user, $password);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->connection->exec('SET hnsw.ef_search = 20');

            Logger::info('Successfully connected to PostgreSQL database');

            return $this->connection;
        } catch (PDOException $e) {
            Logger::exception($e, 'getConnection');
            throw DatabaseException::connectionError($e->getMessage(), $e);
        }
    }

    /**
     * Initialize the database schema: enables the pgvector extension, creates the
     * vectors table with a UNIQUE constraint on (elementId, siteId, chunkIndex),
     * and adds B-tree, GIN (full-text), and HNSW (cosine similarity) indexes.
     *
     * @throws DatabaseException If any DDL statement fails
     */
    public function initializeSchema(): void
    {
        $db = $this->getConnection();

        try {
            $db->exec('CREATE EXTENSION IF NOT EXISTS vector');

            $db->exec('
                CREATE TABLE IF NOT EXISTS ' . self::TABLE_NAME . ' (
                    id SERIAL PRIMARY KEY,
                    "elementId" INTEGER NOT NULL,
                    "siteId" INTEGER NOT NULL,
                    "chunkIndex" INTEGER NOT NULL DEFAULT 0,
                    "totalChunks" INTEGER NOT NULL DEFAULT 1,
                    vector vector(' . $this->getVectorDimensions() . ') NOT NULL,
                    content TEXT,
                    "dateCreated" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    "dateUpdated" TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE("elementId", "siteId", "chunkIndex")
                )
            ');

            $db->exec('CREATE INDEX IF NOT EXISTS idx_elementId ON ' . self::TABLE_NAME . '("elementId")');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_siteId ON ' . self::TABLE_NAME . '("siteId")');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_chunkIndex ON ' . self::TABLE_NAME . '("chunkIndex")');

            $db->exec('CREATE INDEX IF NOT EXISTS idx_content_gin ON ' . self::TABLE_NAME . ' USING gin(to_tsvector(\'english\', COALESCE(content, \'\')))');

            $db->exec('
                CREATE INDEX IF NOT EXISTS idx_vector_cosine ON ' . self::TABLE_NAME . '
                USING hnsw (vector vector_cosine_ops) WITH (m = 16, ef_construction = 64)
            ');

            Logger::info('PostgreSQL schema initialized successfully with pgvector extension');
        } catch (PDOException $e) {
            Logger::exception($e, 'initializeSchema');
            throw DatabaseException::schemaInitFailed($e);
        }
    }

    /**
     * Check whether the vectors table exists in the public schema.
     *
     * Only catches DatabaseException (unconfigured database) and returns false.
     * PDOException from a broken connection will bubble up to the caller.
     *
     * @throws PDOException If the connection is configured but broken
     */
    public function isSchemaInitialized(): bool
    {
        try {
            $db = $this->getConnection();

            $result = $db->query('
                SELECT tablename
                FROM pg_tables
                WHERE schemaname = \'public\'
                AND tablename = \'' . self::TABLE_NAME . '\'
            ');

            return $result->fetch() !== false;
        } catch (DatabaseException) {
            return false;
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
        $db = $this->getConnection();

        try {
            $stmt = $db->prepare('DELETE FROM ' . self::TABLE_NAME);
            $stmt->execute();
            $count = $stmt->rowCount();

            Logger::info('Cleared all vectors', ['count' => $count]);

            return $count;
        } catch (PDOException $e) {
            Logger::exception($e, 'clearAllVectors');
            throw DatabaseException::queryFailed('clearAllVectors', $e);
        }
    }

    /**
     * Drop and reinitialize the vectors table.
     *
     * @throws DatabaseException If connection or DDL operations fail
     */
    public function wipeDatabase(): void
    {
        $db = $this->getConnection();

        try {
            $db->exec('DROP TABLE IF EXISTS ' . self::TABLE_NAME);
            $this->connection = null;
            Craft::$app->getCache()->delete(self::SCHEMA_CACHE_KEY);
            $this->initializeSchema();
            Logger::info('Database wiped and reinitialized');
        } catch (PDOException $e) {
            Logger::exception($e, 'wipeDatabase');
            throw DatabaseException::queryFailed('wipeDatabase', $e);
        }
    }

    /**
     * Fetch dashboard statistics (entry count, chunk count, last indexed date) with connection status.
     *
     * @return array{entryCount: int, chunkCount: int, lastIndexed: string|null, isConnected: bool, error: string|null}
     * @throws DatabaseException If connection fails or queries fail
     */
    /**
     * Get the configured vector dimensions from plugin settings.
     */
    private function getVectorDimensions(): int
    {
        return AiSearch::getInstance()->getSettings()->vectorDimensions;
    }

    /**
     * Return a map keyed by "elementId-siteId" with chunkCount and lastIndexed,
     * for use by the debug view to determine per-entry indexing status.
     *
     * @return array<string, array{chunkCount: int, lastIndexed: string}>
     * @throws DatabaseException
     */
    public function getIndexedSummary(?int $siteId = null): array
    {
        $db = $this->getConnection();

        try {
            $sql = '
                SELECT "elementId", "siteId", COUNT(*) AS "chunkCount", MAX("dateUpdated") AS "lastIndexed"
                FROM ' . self::TABLE_NAME;
            $params = [];
            if ($siteId !== null) {
                $sql .= ' WHERE "siteId" = :siteId';
                $params[':siteId'] = $siteId;
            }
            $sql .= ' GROUP BY "elementId", "siteId"';

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $map = [];
            while ($row = $stmt->fetch()) {
                $key = $row['elementId'] . '-' . $row['siteId'];
                $map[$key] = [
                    'chunkCount' => (int)$row['chunkCount'],
                    'lastIndexed' => $row['lastIndexed'],
                ];
            }
            return $map;
        } catch (PDOException $e) {
            Logger::exception($e, 'getIndexedSummary');
            throw DatabaseException::queryFailed('getIndexedSummary', $e);
        }
    }

    /**
     * Fetch all chunk rows for an element, ordered by chunkIndex.
     *
     * @return array<int, array{chunkIndex: int, totalChunks: int, content: ?string, dateUpdated: string}>
     * @throws DatabaseException
     */
    public function getVectorsForElement(int $elementId, int $siteId): array
    {
        $db = $this->getConnection();

        try {
            $stmt = $db->prepare('
                SELECT "chunkIndex", "totalChunks", content, "dateUpdated"
                FROM ' . self::TABLE_NAME . '
                WHERE "elementId" = :elementId AND "siteId" = :siteId
                ORDER BY "chunkIndex" ASC
            ');
            $stmt->execute([':elementId' => $elementId, ':siteId' => $siteId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            Logger::exception($e, 'getVectorsForElement');
            throw DatabaseException::queryFailed('getVectorsForElement', $e);
        }
    }

    public function getStats(bool $useCache = true): array
    {
        $cache = Craft::$app->getCache();
        $cacheKey = 'aisearch_dashboard_stats';

        if ($useCache) {
            $cached = $cache->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        $db = $this->getConnection();

        try {
            $stmt = $db->query('
                SELECT
                    COUNT(DISTINCT "elementId") AS "entryCount",
                    COUNT(*) AS "chunkCount",
                    MAX("dateUpdated") AS "lastIndexed"
                FROM ' . self::TABLE_NAME
            );
            $row = $stmt->fetch();

            $stats = [
                'entryCount' => (int)($row['entryCount'] ?? 0),
                'chunkCount' => (int)($row['chunkCount'] ?? 0),
                'lastIndexed' => $row['lastIndexed'] ?? null,
                'isConnected' => true,
                'error' => null,
            ];
        } catch (PDOException $e) {
            Logger::exception($e, 'getStats');
            throw DatabaseException::queryFailed('getStats', $e);
        }

        $cache->set($cacheKey, $stats, 60);

        return $stats;
    }

    /**
     * Like getStats(), but returns a canonical disconnected-shape on failure
     * instead of throwing. Use from CP pages that need to render even when
     * the vector DB is unreachable.
     */
    public function getStatsSafe(bool $useCache = true): array
    {
        try {
            return $this->getStats($useCache);
        } catch (DatabaseException $e) {
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
        $rawUri = $uri;

        $uri = preg_replace('/^postgres(ql)?:\/\//', '', $uri);

        if (!preg_match('/^([^:]+):([^@]+)@([^:\/]+):?(\d+)?\/(.+)$/', $uri, $matches)) {
            Logger::warning('Failed to parse PostgreSQL connection URI', [
                'expected' => 'postgresql://user:password@host:port/database',
                'received' => preg_replace('/\/\/[^@]*@/', '//[redacted]@', $rawUri),
            ]);

            return null;
        }

        return [
            'user' => urldecode($matches[1]),
            'password' => urldecode($matches[2]),
            'host' => $matches[3],
            'port' => !empty($matches[4]) ? (int)$matches[4] : 5432,
            'database' => $matches[5],
        ];
    }
}
