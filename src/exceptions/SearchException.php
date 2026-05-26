<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use Throwable;

class SearchException extends SmartSearchException
{
    public static function semanticSearchFailed(string $reason, Throwable $previous): self
    {
        return self::build("Semantic search failed: {$reason}", ErrorCode::SEARCH_SEMANTIC_FAILED, $previous);
    }

    public static function aiAnswerFailed(string $reason, Throwable $previous): self
    {
        return self::build("AI Answer search failed: {$reason}", ErrorCode::SEARCH_RAG_FAILED, $previous);
    }

    public static function ragLlmFailed(string $reason, Throwable $previous): self
    {
        return self::build("AI Answer LLM call failed: {$reason}", ErrorCode::SEARCH_RAG_LLM_ERROR, $previous);
    }

    public static function vectorQueryFailed(Throwable $previous): self
    {
        return self::build(
            "Failed to perform vector similarity search: {$previous->getMessage()}",
            ErrorCode::SEARCH_VECTOR_QUERY_FAILED,
            $previous
        );
    }

    public static function indexEntryNotFound(int $entryId, int $siteId): self
    {
        return self::build("Entry #{$entryId} not found for site #{$siteId}", ErrorCode::SEARCH_ENTRY_NOT_FOUND);
    }

    public static function indexEntryMissingUrl(int $entryId, int $siteId): self
    {
        return self::build("Entry #{$entryId} on site #{$siteId} has no URL and cannot be indexed", ErrorCode::SEARCH_ENTRY_MISSING_URL);
    }
}
