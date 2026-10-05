<?php

namespace ghoststreet\craftsmartsearch\helpers;

use Craft;
use Locale;
use Wamania\Snowball\NotFoundException;
use Wamania\Snowball\Stemmer\Stemmer as SnowballStemmer;
use Wamania\Snowball\StemmerFactory;

/**
 * Tokenising and Snowball stemming in PHP.
 *
 * resolveLanguage() maps a Craft site's locale onto an ISO 639-1 code, which
 * StemmerFactory accepts directly. A language Snowball has no algorithm for is
 * tokenised but not stemmed.
 */
final class Stemmer
{
    /** Longer than this is a URL, a path or a hash, not a searchable word. */
    private const MAX_TOKEN_LENGTH = 40;

    /**
     * Resolves from the Craft site's language, so `en-US`, `en-GB` and `en-AU` all
     * collapse to `en`. Stored per row at index time and re-resolved at query time.
     */
    public static function resolveLanguage(?int $siteId = null): string
    {
        $site = $siteId !== null
            ? Craft::$app->getSites()->getSiteById($siteId)
            : Craft::$app->getSites()->getPrimarySite();

        return Locale::getPrimaryLanguage($site->language ?? 'en');
    }

    /**
     * Snowball's English stopword list. They match the raw lowercased token rather than
     * its stem, because a stopword is discarded before stemming.
     */
    private const ENGLISH_STOPWORDS = [
        'i', 'me', 'my', 'myself', 'we', 'our', 'ours', 'ourselves', 'you', 'your', 'yours',
        'yourself', 'yourselves', 'he', 'him', 'his', 'himself', 'she', 'her', 'hers',
        'herself', 'it', 'its', 'itself', 'they', 'them', 'their', 'theirs', 'themselves',
        'what', 'which', 'who', 'whom', 'this', 'that', 'these', 'those', 'am', 'is', 'are',
        'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'having', 'do', 'does',
        'did', 'doing', 'a', 'an', 'the', 'and', 'but', 'if', 'or', 'because', 'as', 'until',
        'while', 'of', 'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into',
        'through', 'during', 'before', 'after', 'above', 'below', 'to', 'from', 'up', 'down',
        'in', 'out', 'on', 'off', 'over', 'under', 'again', 'further', 'then', 'once', 'here',
        'there', 'when', 'where', 'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more',
        'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so',
        'than', 'too', 'very', 's', 't', 'can', 'will', 'just', 'don', 'should', 'now',
    ];

    /** @var array<string, SnowballStemmer|null> language name => stemmer, or null for none */
    private static array $stemmers = [];

    /**
     * Split text into lowercased tokens **in document order, without de-duplication**.
     *
     * Both term frequency and phrase adjacency read the raw sequence, so neither
     * collapsing duplicates nor sorting is safe here.
     *
     * Digits are kept: a boost rule like "1 bedroom" is meaningless without them, and
     * '3.5 bathrooms' tokenises to '3.5' and 'bathroom'.
     *
     * @return string[]
     */
    private static function tokenize(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}.]+/u', ' ', $text) ?? $text;

        $tokens = [];
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $word = trim($word, '.');
            $length = mb_strlen($word, 'UTF-8');
            if ($length >= 1 && $length <= self::MAX_TOKEN_LENGTH) {
                $tokens[] = $word;
            }
        }

        return $tokens;
    }

    /**
     * Stem a single lowercased token. Only pure-letter tokens are stemmed; numbers and
     * mixed tokens pass through.
     */
    private static function stem(string $token, string $language): string
    {
        if (!preg_match('/^\p{L}+$/u', $token)) {
            return $token;
        }

        $stemmer = self::forLanguage($language);
        if ($stemmer === null) {
            return $token;
        }

        return $stemmer->stem($token) ?: $token;
    }

    /**
     * Tokenise and stem, returning ordered `[raw, stem]` pairs. Pairs rather than stems
     * alone because the postings table stores both: the stem is what a query matches,
     * the raw form is what the typo corrector compares against.
     *
     * @return array<array{0: string, 1: string}>
     */
    public static function tokenizeAndStem(string $text, string $language): array
    {
        $pairs = [];
        foreach (self::tokenize($text) as $raw) {
            $pairs[] = [$raw, self::stem($raw, $language)];
        }

        return $pairs;
    }

    /**
     * tokenizeAndStem() without stopwords: what the postings store and a query matches.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function terms(string $text, string $language): array
    {
        return array_values(array_filter(
            self::tokenizeAndStem($text, $language),
            static fn(array $pair): bool => !self::isStopword($pair[0], $language),
        ));
    }

    /**
     * Only English has a stopword list. Every other language keeps its stopwords, which
     * costs some index size and a little ranking noise rather than correctness.
     */
    private static function isStopword(string $rawToken, string $language): bool
    {
        return $language === 'en' && in_array($rawToken, self::ENGLISH_STOPWORDS, true);
    }

    /** Null for any language Snowball has no algorithm for. */
    private static function forLanguage(string $language): ?SnowballStemmer
    {
        if (!array_key_exists($language, self::$stemmers)) {
            try {
                self::$stemmers[$language] = StemmerFactory::create($language);
            } catch (NotFoundException) {
                self::$stemmers[$language] = null;
            }
        }

        return self::$stemmers[$language];
    }
}
