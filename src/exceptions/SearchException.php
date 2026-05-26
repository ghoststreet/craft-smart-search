<?php

namespace ghoststreet\craftsmartsearch\exceptions;

use Throwable;

class SearchException extends SmartSearchException
{
    public static function semanticSearchFailed(string $reason, Throwable $previous): self
    {
        $e = new self("Semantic search failed: {$reason}", 0, $previous);
        $e->errorCode = ErrorCode::SEARCH_SEMANTIC_FAILED;
        return $e;
    }

    public static function aiAnswerFailed(string $reason, Throwable $previous): self
    {
        $e = new self("AI Answer search failed: {$reason}", 0, $previous);
        $e->errorCode = ErrorCode::SEARCH_RAG_FAILED;
        return $e;
    }

    public static function ragLlmFailed(string $reason, Throwable $previous): self
    {
        $e = new self("AI Answer LLM call failed: {$reason}", 0, $previous);
        $e->errorCode = ErrorCode::SEARCH_RAG_LLM_ERROR;
        return $e;
    }

    public static function vectorQueryFailed(Throwable $previous): self
    {
        $e = new self(
            "Failed to perform vector similarity search: {$previous->getMessage()}",
            0,
            $previous
        );
        $e->errorCode = ErrorCode::SEARCH_VECTOR_QUERY_FAILED;
        return $e;
    }

    public static function indexEntryNotFound(int $entryId, int $siteId): self
    {
        $e = new self("Entry #{$entryId} not found for site #{$siteId}");
        $e->errorCode = ErrorCode::SEARCH_ENTRY_NOT_FOUND;
        return $e;
    }

    public static function indexEntryMissingUrl(int $entryId, int $siteId): self
    {
        $e = new self("Entry #{$entryId} on site #{$siteId} has no URL and cannot be indexed");
        $e->errorCode = ErrorCode::SEARCH_ENTRY_MISSING_URL;
        return $e;
    }
}
