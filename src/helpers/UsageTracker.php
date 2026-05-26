<?php

namespace ghoststreet\craftsmartsearch\helpers;

/**
 * Per-request accumulator for OpenAI token usage.
 *
 * Reset at the start of a search request by the controller, then read after
 * the search completes. Lets us capture token usage without changing the
 * return signatures of every service method (generateEmbedding/etc. just call
 * ::addEmbedding or ::markEmbeddingCached).
 */
final class UsageTracker
{
    private static int $embeddingTokens = 0;
    private static int $aiAnswerInputTokens = 0;
    private static int $aiAnswerOutputTokens = 0;
    private static bool $embeddingApiCalled = false;
    private static bool $embeddingHit = false;
    private static ?string $embeddingModel = null;
    private static ?string $aiAnswerModel = null;

    public static function reset(): void
    {
        self::$embeddingTokens = 0;
        self::$aiAnswerInputTokens = 0;
        self::$aiAnswerOutputTokens = 0;
        self::$embeddingApiCalled = false;
        self::$embeddingHit = false;
        self::$embeddingModel = null;
        self::$aiAnswerModel = null;
    }

    public static function addEmbedding(string $model, int $promptTokens): void
    {
        self::$embeddingTokens += $promptTokens;
        self::$embeddingModel = $model;
        self::$embeddingApiCalled = true;
        self::$embeddingHit = true;
    }

    public static function markEmbeddingCached(string $model): void
    {
        self::$embeddingModel ??= $model;
        self::$embeddingHit = true;
    }

    public static function addRag(string $model, int $inputTokens, int $outputTokens): void
    {
        self::$aiAnswerModel = $model;
        self::$aiAnswerInputTokens += $inputTokens;
        self::$aiAnswerOutputTokens += $outputTokens;
    }

    public static function snapshot(): array
    {
        return [
            'embeddingTokens' => self::$embeddingTokens,
            'aiAnswerInputTokens' => self::$aiAnswerInputTokens,
            'aiAnswerOutputTokens' => self::$aiAnswerOutputTokens,
            'embeddingCached' => self::$embeddingHit && !self::$embeddingApiCalled,
            'embeddingModel' => self::$embeddingModel,
            'aiAnswerModel' => self::$aiAnswerModel,
        ];
    }
}
