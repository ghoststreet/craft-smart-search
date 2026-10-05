<?php

namespace ghoststreet\craftsmartsearch\helpers;

/**
 * The "Label: " prefix a field's text is indexed with, fenced by two private-use
 * characters so the stored chunk says exactly where each prefix is. The embedding
 * reads it with the prefix, a reader without, and genuine text that happens to look
 * like "Label: " is never touched. Content is scrubbed of the fence characters first,
 * so it cannot forge a prefix.
 *
 * The fences sit apart from words by a space, so no tokenizer glues them onto a term.
 */
final class FieldPrefix
{
    private const OPEN = "\u{E000}";

    private const CLOSE = "\u{E001}";

    public static function wrap(string $label): string
    {
        return self::OPEN . ' ' . $label . ': ' . self::CLOSE . ' ';
    }

    public static function scrub(string $text): string
    {
        return str_replace([self::OPEN, self::CLOSE], '', $text);
    }

    /** The text the embedding, keyword index and AI answer read: prefixes kept, fences dropped. */
    public static function forEmbedding(string $text): string
    {
        return str_replace([self::OPEN . ' ', ' ' . self::CLOSE . ' ', self::OPEN, self::CLOSE], ['', ' ', '', ''], $text);
    }

    /**
     * The text a reader sees: prefixes dropped. A chunk boundary can fall inside a
     * prefix (overlap, sentence split), leaving a close with no open at the start or
     * an open with no close at the end; the partial prefix goes with it.
     */
    public static function forDisplay(string $text): string
    {
        $open = strpos($text, self::OPEN);
        $close = strpos($text, self::CLOSE);
        if ($close !== false && ($open === false || $close < $open)) {
            $text = substr($text, $close + strlen(self::CLOSE));
        }

        $open = strrpos($text, self::OPEN);
        $close = strrpos($text, self::CLOSE);
        if ($open !== false && ($close === false || $open > $close)) {
            $text = substr($text, 0, $open);
        }

        return (string)preg_replace('/' . self::OPEN . '[^' . self::CLOSE . ']*' . self::CLOSE . ' ?/u', '', $text);
    }
}
