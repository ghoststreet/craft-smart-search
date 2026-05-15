<?php

namespace ghoststreet\craftaisearch\helpers;

/**
 * Per-request accumulator for OpenAI token usage.
 *
 * Reset at the start of a search request by the controller, then read after the search
 * completes. Lets us capture token usage without changing the return signatures of every
 * service method (generateEmbedding/etc. just call ::addEmbedding).
 */
final class UsageTracker
{
    private static int $embeddingTokens = 0;
    private static int $ragInputTokens = 0;
    private static int $ragOutputTokens = 0;
    private static bool $embeddingCached = true; // assume cached until a real API call happens
    private static bool $embeddingHit = false;
    private static ?string $embeddingModel = null;
    private static ?string $ragModel = null;

    public static function reset(): void
    {
        self::$embeddingTokens = 0;
        self::$ragInputTokens = 0;
        self::$ragOutputTokens = 0;
        self::$embeddingCached = true;
        self::$embeddingHit = false;
        self::$embeddingModel = null;
        self::$ragModel = null;
    }

    public static function addEmbedding(string $model, int $promptTokens): void
    {
        self::$embeddingTokens += $promptTokens;
        self::$embeddingModel = $model;
        self::$embeddingCached = false;
        self::$embeddingHit = true;
    }

    public static function markEmbeddingCached(string $model): void
    {
        self::$embeddingModel ??= $model;
        self::$embeddingHit = true;
    }

    public static function addRag(string $model, int $inputTokens, int $outputTokens): void
    {
        self::$ragModel = $model;
        self::$ragInputTokens += $inputTokens;
        self::$ragOutputTokens += $outputTokens;
    }

    public static function snapshot(): array
    {
        return [
            'embeddingTokens' => self::$embeddingTokens,
            'ragInputTokens' => self::$ragInputTokens,
            'ragOutputTokens' => self::$ragOutputTokens,
            'embeddingCached' => self::$embeddingHit && self::$embeddingCached,
            'embeddingModel' => self::$embeddingModel,
            'ragModel' => self::$ragModel,
        ];
    }
}
