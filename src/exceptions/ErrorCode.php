<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use Craft;
use Throwable;

enum ErrorCode : string
{
    case AI_ANSWER_FAILED = 'AI_ANSWER_FAILED';
    case AI_ANSWER_LLM_ERROR = 'AI_ANSWER_LLM_ERROR';
    case SEARCH_VALIDATION_FAILED = 'SEARCH_VALIDATION_FAILED';

    case EMBEDDING_EMPTY_TEXT = 'EMBEDDING_EMPTY_TEXT';
    case EMBEDDING_RATE_LIMITED = 'EMBEDDING_RATE_LIMITED';
    case EMBEDDING_QUOTA_EXCEEDED = 'EMBEDDING_QUOTA_EXCEEDED';
    case EMBEDDING_INVALID_API_KEY = 'EMBEDDING_INVALID_API_KEY';
    case EMBEDDING_API_ERROR = 'EMBEDDING_API_ERROR';

    case RATE_LIMIT_REQUESTS = 'RATE_LIMIT_REQUESTS';
    case RATE_LIMIT_CONCURRENCY = 'RATE_LIMIT_CONCURRENCY';

    case CONFIG_MISSING_API_KEY = 'CONFIG_MISSING_API_KEY';

    case AUTH_CSRF_INVALID = 'AUTH_CSRF_INVALID';
    case AUTH_TOKEN_INVALID = 'AUTH_TOKEN_INVALID';
    case AUTH_ORIGIN_DENIED = 'AUTH_ORIGIN_DENIED';

    case UNKNOWN = 'UNKNOWN';

    /** The stable code for any Throwable. Anything not ours is UNKNOWN. */
    public static function for(Throwable $e): self
    {
        return $e instanceof SmartSearchException ? $e->errorCode() : self::UNKNOWN;
    }

    /** The curated, user-facing message, localized. */
    public function translated(): string
    {
        return Craft::t('smart-search', $this->message());
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::SEARCH_VALIDATION_FAILED,
            self::AUTH_CSRF_INVALID => 400,
            self::AUTH_TOKEN_INVALID => 401,
            self::AUTH_ORIGIN_DENIED => 403,
            self::EMBEDDING_RATE_LIMITED,
            self::EMBEDDING_QUOTA_EXCEEDED,
            self::RATE_LIMIT_REQUESTS,
            self::RATE_LIMIT_CONCURRENCY => 429,
            self::EMBEDDING_INVALID_API_KEY,
            self::CONFIG_MISSING_API_KEY => 503,
            default => 500,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::AI_ANSWER_FAILED => 'AI summary failed. Please try again.',
            self::AI_ANSWER_LLM_ERROR => 'The AI provider rejected the summary request. An administrator can find details in the Smart Search log.',
            self::SEARCH_VALIDATION_FAILED => 'Your search request was invalid.',
            self::EMBEDDING_EMPTY_TEXT => 'Cannot generate an embedding for empty text.',
            self::EMBEDDING_RATE_LIMITED => 'The API provider’s rate limit was reached. Please retry shortly.',
            self::EMBEDDING_QUOTA_EXCEEDED => 'Quota exceeded. Check the billing on your API provider account.',
            self::EMBEDDING_INVALID_API_KEY => 'The API provider rejected the request: the API key is invalid.',
            self::EMBEDDING_API_ERROR => 'The embedding request failed.',
            self::RATE_LIMIT_REQUESTS => 'Too many requests. Slow down and retry shortly.',
            self::RATE_LIMIT_CONCURRENCY => 'Too many concurrent requests. Try again in a moment.',
            self::CONFIG_MISSING_API_KEY => 'AI Answer needs an AI provider API key. Search itself does not, and is unaffected.',
            self::AUTH_CSRF_INVALID => 'Missing or invalid CSRF token.',
            self::AUTH_TOKEN_INVALID => 'Invalid API token.',
            self::AUTH_ORIGIN_DENIED => 'Requests from this origin are not allowed.',
            self::UNKNOWN => 'Something went wrong. The administrator can find details in the Smart Search log.',
        };
    }
}
