<?php

namespace ghoststreet\craftaisearch\exceptions;

use Throwable;

/**
 * Exception for embedding generation failures.
 * Thrown when OpenAI API calls fail or return invalid data.
 */
class EmbeddingException extends AiSearchException
{
    public static function emptyText(): self
    {
        return new self('Cannot generate embedding: text cannot be empty');
    }

    public static function rateLimited(Throwable $previous): self
    {
        $e = new self(
            'OpenAI API rate limit exceeded. Please wait a moment and try again.',
            0,
            $previous
        );
        $e->httpStatus = 429;
        return $e;
    }

    public static function quotaExceeded(Throwable $previous): self
    {
        $e = new self(
            'OpenAI API quota exceeded. Please check your OpenAI account billing.',
            0,
            $previous
        );
        $e->httpStatus = 429;
        return $e;
    }

    public static function invalidApiKey(Throwable $previous): self
    {
        $e = new self(
            'Invalid OpenAI API key. Please check your plugin settings.',
            0,
            $previous
        );
        $e->httpStatus = 503;
        return $e;
    }

    public static function apiError(string $message, Throwable $previous): self
    {
        return new self("Failed to generate embedding: {$message}", 0, $previous);
    }
}
