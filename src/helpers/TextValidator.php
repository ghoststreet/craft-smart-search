<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Normalizer;

/**
 * Helper for text validation operations.
 *
 * Consolidates the repeated empty(trim($text)) pattern across the codebase
 * into reusable methods with consistent behavior.
 */
final class TextValidator
{
    public const EMBEDDING_INPUT_BYTE_CAP = 8000;

    /**
     * Check if a string is empty or contains only whitespace.
     *
     * @param string|null $text The text to check
     * @return bool True if the text is null, empty, or whitespace-only
     */
    public static function isEmpty(?string $text): bool
    {
        return $text === null || trim($text) === '';
    }

    /**
     * NFC-normalize, strip ASCII control chars (keeping \n and \t), drop OpenAI
     * chat-template control sequences, and trim. Run on every user-supplied
     * query before it reaches embeddings or LLM prompts.
     */
    public static function sanitizeQuery(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/<\|[^|>]*\|>/u', '', $text) ?? $text;
        $text = preg_replace('/(^|\n)\s*(```+|~~~+)/u', '$1', $text) ?? $text;

        return trim($text);
    }

    /**
     * Same as sanitizeQuery() with an additional byte-length cap, applied right
     * before sending the text to the embeddings endpoint.
     */
    public static function sanitizeEmbeddingInput(string $text): string
    {
        $clean = self::sanitizeQuery($text);

        if (strlen($clean) > self::EMBEDDING_INPUT_BYTE_CAP) {
            $clean = substr($clean, 0, self::EMBEDDING_INPUT_BYTE_CAP);
        }

        return $clean;
    }
}
