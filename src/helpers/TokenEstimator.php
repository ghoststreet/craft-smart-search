<?php

namespace ghoststreet\craftsmartsearch\helpers;

/**
 * Helper for estimating token counts from text.
 * Uses the approximate ratio of 4 characters per token for English text.
 */
final class TokenEstimator
{
    /**
     * Average number of characters per token for English text.
     * This is a rough estimate; actual token counts vary by tokenizer.
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Estimate the number of tokens in a text string.
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * Estimate the number of characters for a given token count.
     */
    public static function estimateChars(int $tokens): int
    {
        return $tokens * self::CHARS_PER_TOKEN;
    }
}
