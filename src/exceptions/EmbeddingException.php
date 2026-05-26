<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use Throwable;

class EmbeddingException extends SmartSearchException
{
    public static function emptyText(): self
    {
        return self::build('Cannot generate embedding: text cannot be empty', ErrorCode::EMBEDDING_EMPTY_TEXT);
    }

    public static function rateLimited(Throwable $previous): self
    {
        return self::build('OpenAI API rate limit exceeded.', ErrorCode::EMBEDDING_RATE_LIMITED, $previous);
    }

    public static function quotaExceeded(Throwable $previous): self
    {
        return self::build('OpenAI API quota exceeded.', ErrorCode::EMBEDDING_QUOTA_EXCEEDED, $previous);
    }

    public static function invalidApiKey(Throwable $previous): self
    {
        return self::build('Invalid OpenAI API key.', ErrorCode::EMBEDDING_INVALID_API_KEY, $previous);
    }

    public static function apiError(string $message, Throwable $previous): self
    {
        return self::build("Failed to generate embedding: {$message}", ErrorCode::EMBEDDING_API_ERROR, $previous);
    }
}
