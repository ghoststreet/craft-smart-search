<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use PDOException;
use Throwable;

class DatabaseException extends SmartSearchException
{
    public static function queryFailed(string $operation, Throwable $previous, ?string $table = null): self
    {
        if ($previous instanceof PDOException && ($previous->getCode() === '42P01' || str_contains($previous->getMessage(), 'SQLSTATE[42P01]'))) {
            $label = $table !== null ? "\"{$table}\"" : 'vector table';
            return self::build("The vector table {$label} does not exist.", ErrorCode::DATABASE_TABLE_MISSING, $previous);
        }

        return self::build(
            "Database query failed in {$operation}: {$previous->getMessage()}",
            ErrorCode::DATABASE_QUERY_FAILED,
            $previous
        );
    }

    public static function configurationIncomplete(array $missingFields): self
    {
        $fieldList = implode(', ', $missingFields);
        return self::build("PostgreSQL configuration incomplete. Missing: {$fieldList}", ErrorCode::DATABASE_CONFIG_INCOMPLETE);
    }

    public static function connectionError(string $message, ?Throwable $previous = null): self
    {
        return self::build("PostgreSQL connection error: {$message}", ErrorCode::DATABASE_CONNECTION_ERROR, $previous);
    }
}
