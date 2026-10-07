<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use Throwable;

class EmbeddingException extends SmartSearchException
{
    public static function missingApiKey(): self
    {
        return self::build(null, ErrorCode::CONFIG_MISSING_API_KEY);
    }

    public static function emptyText(): self
    {
        return self::build(null, ErrorCode::EMBEDDING_EMPTY_TEXT);
    }

    public static function rateLimited(string $publicMessage, Throwable $previous): self
    {
        return self::build(self::withCause($publicMessage, $previous), ErrorCode::EMBEDDING_RATE_LIMITED, $previous, $publicMessage);
    }

    public static function quotaExceeded(string $publicMessage, Throwable $previous): self
    {
        return self::build(self::withCause($publicMessage, $previous), ErrorCode::EMBEDDING_QUOTA_EXCEEDED, $previous, $publicMessage);
    }

    public static function invalidApiKey(string $publicMessage, Throwable $previous): self
    {
        return self::build(self::withCause($publicMessage, $previous), ErrorCode::EMBEDDING_INVALID_API_KEY, $previous, $publicMessage);
    }

    public static function apiError(string $publicMessage, Throwable $previous): self
    {
        return self::build(self::withCause($publicMessage, $previous), ErrorCode::EMBEDDING_API_ERROR, $previous, $publicMessage);
    }

    /** The log message: the public message plus the provider's or curl's own words. */
    private static function withCause(string $publicMessage, Throwable $previous): string
    {
        return "{$publicMessage} Cause: {$previous->getMessage()}";
    }
}
