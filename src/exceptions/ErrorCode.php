<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use Craft;
use Throwable;

enum ErrorCode : string
{
    case SEARCH_SEMANTIC_FAILED = 'SEARCH_SEMANTIC_FAILED';
    case SEARCH_RAG_FAILED = 'SEARCH_RAG_FAILED';
    case SEARCH_RAG_LLM_ERROR = 'SEARCH_RAG_LLM_ERROR';
    case SEARCH_VECTOR_QUERY_FAILED = 'SEARCH_VECTOR_QUERY_FAILED';
    case SEARCH_ENTRY_NOT_FOUND = 'SEARCH_ENTRY_NOT_FOUND';
    case SEARCH_ENTRY_MISSING_URL = 'SEARCH_ENTRY_MISSING_URL';
    case SEARCH_VALIDATION_FAILED = 'SEARCH_VALIDATION_FAILED';

    case EMBEDDING_EMPTY_TEXT = 'EMBEDDING_EMPTY_TEXT';
    case EMBEDDING_RATE_LIMITED = 'EMBEDDING_RATE_LIMITED';
    case EMBEDDING_QUOTA_EXCEEDED = 'EMBEDDING_QUOTA_EXCEEDED';
    case EMBEDDING_INVALID_API_KEY = 'EMBEDDING_INVALID_API_KEY';
    case EMBEDDING_API_ERROR = 'EMBEDDING_API_ERROR';

    case DATABASE_QUERY_FAILED = 'DATABASE_QUERY_FAILED';
    case DATABASE_TABLE_MISSING = 'DATABASE_TABLE_MISSING';
    case DATABASE_CONFIG_INCOMPLETE = 'DATABASE_CONFIG_INCOMPLETE';
    case DATABASE_CONNECTION_ERROR = 'DATABASE_CONNECTION_ERROR';

    case RATE_LIMIT_REQUESTS = 'RATE_LIMIT_REQUESTS';
    case RATE_LIMIT_CONCURRENCY = 'RATE_LIMIT_CONCURRENCY';

    case CONFIG_MISSING_API_KEY = 'CONFIG_MISSING_API_KEY';

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
            self::SEARCH_VALIDATION_FAILED => 400,
            self::SEARCH_ENTRY_NOT_FOUND => 404,
            self::SEARCH_ENTRY_MISSING_URL => 422,
            self::EMBEDDING_RATE_LIMITED,
            self::EMBEDDING_QUOTA_EXCEEDED,
            self::RATE_LIMIT_REQUESTS,
            self::RATE_LIMIT_CONCURRENCY => 429,
            self::EMBEDDING_INVALID_API_KEY,
            self::DATABASE_TABLE_MISSING,
            self::DATABASE_CONFIG_INCOMPLETE,
            self::DATABASE_CONNECTION_ERROR,
            self::CONFIG_MISSING_API_KEY => 503,
            default => 500,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::SEARCH_SEMANTIC_FAILED => 'Semantic search failed. Please try again.',
            self::SEARCH_RAG_FAILED => 'AI summary failed. Please try again.',
            self::SEARCH_RAG_LLM_ERROR => 'The AI provider rejected the summary request. An administrator can find details in the Smart Search log.',
            self::SEARCH_VECTOR_QUERY_FAILED => 'Vector similarity search failed.',
            self::SEARCH_ENTRY_NOT_FOUND => 'The requested entry could not be found.',
            self::SEARCH_ENTRY_MISSING_URL => 'Entry has no URL on this site and cannot be indexed.',
            self::SEARCH_VALIDATION_FAILED => 'Your search request was invalid.',
            self::EMBEDDING_EMPTY_TEXT => 'Cannot generate an embedding for empty text.',
            self::EMBEDDING_RATE_LIMITED => 'OpenAI rate limit reached. Please retry shortly.',
            self::EMBEDDING_QUOTA_EXCEEDED => 'OpenAI quota exceeded. Check your OpenAI account billing.',
            self::EMBEDDING_INVALID_API_KEY => 'OpenAI rejected the request: the API key is invalid.',
            self::EMBEDDING_API_ERROR => 'OpenAI embedding request failed.',
            self::DATABASE_QUERY_FAILED => 'A database query failed.',
            self::DATABASE_TABLE_MISSING => 'The vector table does not exist yet. Set up the pgvector schema before indexing.',
            self::DATABASE_CONFIG_INCOMPLETE => 'Database connection is not configured.',
            self::DATABASE_CONNECTION_ERROR => 'Could not connect to the vector database.',
            self::RATE_LIMIT_REQUESTS => 'Too many requests. Slow down and retry shortly.',
            self::RATE_LIMIT_CONCURRENCY => 'Too many concurrent requests. Try again in a moment.',
            self::CONFIG_MISSING_API_KEY => 'An API key is not configured. Please set it in plugin settings.',
            self::UNKNOWN => 'Something went wrong. The administrator can find details in the Smart Search log.',
        };
    }
}
