<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use ghoststreet\craftsmartsearch\SmartSearch;
use PDOException;
use Throwable;

class DatabaseException extends SmartSearchException
{
    public static function queryFailed(string $operation, Throwable $previous): self
    {
        if ($previous instanceof PDOException && ($previous->getCode() === '42P01' || str_contains($previous->getMessage(), 'SQLSTATE[42P01]'))) {
            $settings = SmartSearch::getInstance()?->getSettings();
            $table = $settings ? "{$settings->vectorsSchemaName}.{$settings->vectorsTableName}" : 'vector table';
            $e = new self("The vector table \"{$table}\" does not exist.", 0, $previous);
            $e->errorCode = ErrorCode::DATABASE_TABLE_MISSING;
            return $e;
        }

        $e = new self(
            "Database query failed in {$operation}: {$previous->getMessage()}",
            0,
            $previous
        );
        $e->errorCode = ErrorCode::DATABASE_QUERY_FAILED;
        return $e;
    }

    public static function configurationIncomplete(array $missingFields): self
    {
        $fieldList = implode(', ', $missingFields);
        $e = new self("PostgreSQL configuration incomplete. Missing: {$fieldList}");
        $e->errorCode = ErrorCode::DATABASE_CONFIG_INCOMPLETE;
        return $e;
    }

    public static function connectionError(string $message, ?Throwable $previous = null): self
    {
        $e = new self("PostgreSQL connection error: {$message}", 0, $previous);
        $e->errorCode = ErrorCode::DATABASE_CONNECTION_ERROR;
        return $e;
    }
}
