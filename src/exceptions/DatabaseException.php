<?php

namespace ghoststreet\craftaisearch\exceptions;

use Throwable;

/**
 * Thrown when database operations fail in the AI Search plugin.
 * Use instead of returning null/0 to make errors explicit and distinguishable from empty results.
 */
class DatabaseException extends AiSearchException
{
    /**
     * Create exception for query failures.
     */
    public static function queryFailed(string $operation, Throwable $previous): self
    {
        return new self(
            "Database query failed in {$operation}: {$previous->getMessage()}",
            0,
            $previous
        );
    }

    /**
     * Create exception for schema initialization failures.
     */
    public static function schemaInitFailed(Throwable $previous): self
    {
        return new self(
            "Failed to initialize PostgreSQL schema: {$previous->getMessage()}",
            0,
            $previous
        );
    }

    /**
     * Create exception for incomplete configuration.
     */
    public static function configurationIncomplete(array $missingFields): self
    {
        $fieldList = implode(', ', $missingFields);
        $e = new self("PostgreSQL configuration incomplete. Missing: {$fieldList}");
        $e->httpStatus = 503;
        return $e;
    }

    /**
     * Create exception for connection errors with PDO details.
     */
    public static function connectionError(string $message, Throwable $previous): self
    {
        $e = new self(
            "PostgreSQL connection error: {$message}",
            0,
            $previous
        );
        $e->httpStatus = 503;
        return $e;
    }
}
