<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use Throwable;

class SearchException extends SmartSearchException
{
    public static function aiAnswerFailed(string $reason, Throwable $previous): self
    {
        return self::build(self::compose("AI Answer search failed", $reason, $previous), ErrorCode::AI_ANSWER_FAILED, $previous);
    }

    public static function aiAnswerLlmFailed(string $reason, Throwable $previous): self
    {
        return self::build(self::compose("AI Answer LLM call failed", $reason, $previous), ErrorCode::AI_ANSWER_LLM_ERROR, $previous);
    }

    private static function compose(string $stage, string $reason, Throwable $previous): string
    {
        $detail = trim($previous->getMessage());
        $base = "{$stage}: {$reason}";
        return $detail === '' || str_contains($base, $detail) ? $base : "{$base}\n\nUnderlying error: {$detail}";
    }

    public static function csrfRejected(): self
    {
        return self::build('Missing or invalid CSRF token', ErrorCode::AUTH_CSRF_INVALID);
    }

    public static function invalidApiToken(): self
    {
        return self::build('Invalid API bearer token', ErrorCode::AUTH_TOKEN_INVALID);
    }

    public static function originNotAllowed(string $origin): self
    {
        return self::build($origin === '' ? 'No Origin or Referer header against a configured allowlist' : "Origin {$origin} not allowed", ErrorCode::AUTH_ORIGIN_DENIED);
    }

    public static function invalidQuery(): self
    {
        return self::build('Search query is longer than the allowed length', ErrorCode::SEARCH_VALIDATION_FAILED);
    }

    public static function invalidSiteId(): self
    {
        return self::build('Unknown siteId', ErrorCode::SEARCH_VALIDATION_FAILED);
    }
}
