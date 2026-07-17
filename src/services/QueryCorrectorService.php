<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;
use PDOException;
use yii\base\Component;

/**
 * Builds a typo-tolerant tsquery expression by stemming each query token to the
 * same lexeme form Postgres stores in the GIN index, then looking that lexeme
 * up in the corpus dictionary via trigram similarity (pg_trgm).
 *
 * The output is returned as a bare tsquery string (lexemes already stemmed) so
 * the caller can cast it `::tsquery` without invoking `to_tsquery`, which would
 * stem a second time. Returns null whenever correction is unavailable / not
 * needed — KeywordSearchService then uses its websearch_to_tsquery path alone.
 */
class QueryCorrectorService extends Component
{
    /** Algolia-style edit-distance ceiling — under this length, no correction. */
    private const MIN_TOKEN_LENGTH = 4;

    /** Max corrections fetched per token. */
    private const MAX_VARIANTS_PER_TOKEN = 1;

    /** word_similarity threshold; pg_trgm default is 0.6. */
    private const SIMILARITY_THRESHOLD = 0.5;

    private array $variantCache = [];

    /**
     * Returns a tsquery expression suitable for `(:expr)::tsquery`, or null if
     * correction is unavailable / unnecessary.
     *
     * Output is built from dictionary lexemes only, so casting it to tsquery
     * does not re-stem. Example: typing `runnng` against an English corpus
     * returns `(runnng | run)`.
     */
    public function rewrite(string $query, ?int $siteId = null): ?string
    {
        if (!SmartSearch::getInstance()->getSettings()->enableTypoTolerance) {
            return null;
        }

        $dictionary = SmartSearch::getInstance()->dictionaryService;
        if (!$dictionary->isAvailable()) {
            return null;
        }

        // Bail on queries with phrase quotes or websearch operators — those
        // semantics belong to websearch_to_tsquery and shouldn't be fuzz-expanded.
        if (preg_match('/["\\-]/', $query)) {
            return null;
        }

        $tokens = $this->tokenize($query);
        if (empty($tokens)) {
            return null;
        }

        $language = KeywordSearchService::resolveLanguage($siteId);
        $stemDict = $this->stemDictionaryFor($language);

        try {
            $perToken = $this->lookupVariants($tokens, $dictionary, $stemDict);
        } catch (PDOException $e) {
            Logger::warning('Typo correction lookup failed; falling back to exact keyword search', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $anyCorrected = false;
        $parts = [];
        foreach ($tokens as $token) {
            $info = $perToken[$token];
            $lex = self::cleanLexeme($info['lex']);
            if ($lex === '') {
                continue;
            }

            $candidates = [$lex];
            foreach ($info['variants'] as $variant) {
                if ($variant !== $lex && !in_array($variant, $candidates, true)) {
                    $candidates[] = $variant;
                    $anyCorrected = true;
                }
            }

            $parts[] = count($candidates) === 1
                ? $candidates[0]
                : '(' . implode(' | ', $candidates) . ')';
        }

        if (!$anyCorrected || empty($parts)) {
            return null;
        }

        return implode(' | ', $parts);
    }

    /**
     * Build a tsvector *literal* for boost matching where each query token keeps
     * its position and word tokens carry their dictionary variants at that same
     * position. Casting the literal `::tsvector` (do NOT wrap in to_tsvector —
     * that would re-tokenize and lose the positional variants) preserves phrase
     * adjacency so a stored rule tsquery like `'1' <-> 'bedroom'` matches only
     * when those lexemes are actually adjacent.
     *
     * Order-preserving, keeps EVERY token (numbers, single chars, decimals) —
     * unlike tokenize(), which drops <2-char tokens and de-dups/reorders. Word
     * tokens (letters, length >= 3) are stemmed and typo-expanded via the shared
     * dictionary engine; numbers/short tokens (e.g. "6", "3.5") are kept verbatim
     * so they line up with what phraseto_tsquery stored on the rule side.
     *
     * Always returns a string (never null): with no typos it is just one
     * `lex:pos` per token; the empty query yields `''` (an empty tsvector).
     */
    public function expandedTsvector(string $query, ?int $siteId = null): string
    {
        $tokens = $this->splitPreservingOrder($query);
        if ($tokens === []) {
            return '';
        }

        $dictionary = SmartSearch::getInstance()->dictionaryService;
        $wordTokens = array_values(array_unique(array_column(
            array_filter($tokens, static fn(array $t): bool => $t['word']),
            'raw',
        )));

        $variants = [];
        if ($wordTokens !== [] && $dictionary->isAvailable()) {
            $language = KeywordSearchService::resolveLanguage($siteId);
            try {
                $variants = $this->lookupVariants($wordTokens, $dictionary, $this->stemDictionaryFor($language));
            } catch (PDOException $e) {
                Logger::warning('Boost tsvector variant lookup failed; matching without typo expansion', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $parts = [];
        foreach ($tokens as $token) {
            $pos = $token['pos'];
            if ($token['word'] && isset($variants[$token['raw']])) {
                $info = $variants[$token['raw']];
                $lexemes = array_merge([self::cleanLexeme($info['lex'])], array_map([self::class, 'cleanLexeme'], $info['variants']));
            } elseif ($token['word']) {
                $lexemes = [self::cleanLexeme($token['raw'])];
            } else {
                $lexemes = [trim(preg_replace('/[^\p{L}\p{N}.]+/u', '', $token['raw']) ?? '', '.')];
            }

            foreach (array_unique(array_filter($lexemes, static fn(string $l): bool => $l !== '')) as $lex) {
                $parts[] = "'" . str_replace("'", "''", $lex) . "':" . $pos;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Split on whitespace, keeping every token in order with a 1-based position.
     * A token is a "word" (stem + typo-expand) only when it is all letters and
     * at least 3 long; everything else (numbers, decimals, single chars) is kept
     * verbatim so phrase positions match the rule tsquery.
     *
     * @return array<array{raw: string, pos: int, word: bool}>
     */
    private function splitPreservingOrder(string $query): array
    {
        $raw = preg_split('/\s+/', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];
        foreach ($raw as $i => $word) {
            $tokens[] = [
                'raw' => $word,
                'pos' => $i + 1,
                'word' => (bool)preg_match('/^\p{L}{3,}$/u', $word),
            ];
        }
        return $tokens;
    }

    /**
     * @return string[]
     */
    private function tokenize(string $query): array
    {
        $query = mb_strtolower(trim($query));
        $raw = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = [];
        foreach ($raw as $word) {
            $clean = self::cleanLexeme($word);
            if ($clean === '' || mb_strlen($clean) < 2) {
                continue;
            }
            $tokens[] = $clean;
        }
        return array_values(array_unique($tokens));
    }

    /**
     * Strip everything that tsquery would reject; lexemes are alnum + underscore.
     */
    private static function cleanLexeme(string $word): string
    {
        return preg_replace('/[^\p{L}\p{N}_]+/u', '', $word) ?? '';
    }

    /**
     * Snowball stemmer dictionary name for a tsearch config. `simple` uses the
     * `simple` dictionary (lowercase, no stemming). Anything else assumes the
     * standard `<lang>_stem` Snowball dictionary that ships with Postgres.
     */
    private function stemDictionaryFor(string $language): string
    {
        return $language === 'simple' ? 'simple' : "{$language}_stem";
    }

    /**
     * For each user token, stem it via `ts_lexize` (so the lookup key matches
     * what the dictionary actually stores), then OR in any trigram-similar dict
     * terms. Single round-trip per token; same number of queries as before but
     * keyed on the stemmed form instead of the raw surface form.
     *
     * @param string[] $tokens
     * @return array<string, array{lex: string, variants: string[]}>
     */
    private function lookupVariants(array $tokens, DictionaryService $dictionary, string $stemDict): array
    {
        /* Both the keyword tsquery and the boost tsvector ask this for the same query. */
        $cacheKey = $stemDict . '|' . implode(' ', $tokens);
        if (isset($this->variantCache[$cacheKey])) {
            return $this->variantCache[$cacheKey];
        }

        $terms = $dictionary->qualifiedTermsTable();
        $hasFuzzy = $dictionary->hasExtension('fuzzystrmatch');

        $valuesParts = [];
        $params = [':dict' => $stemDict];
        foreach ($tokens as $i => $token) {
            $valuesParts[] = "(:t{$i}, {$i})";
            $params[":t{$i}"] = $token;
        }
        $valuesList = implode(',', $valuesParts);

        $fuzzyExtra = $hasFuzzy
            ? " AND levenshtein_less_equal(t.term, s.lex, CASE WHEN char_length(s.lex) >= 8 THEN 2 ELSE 1 END) <= CASE WHEN char_length(s.lex) >= 8 THEN 2 ELSE 1 END"
            : '';

        $threshold = self::SIMILARITY_THRESHOLD;
        $minLen = self::MIN_TOKEN_LENGTH;
        $maxPerToken = self::MAX_VARIANTS_PER_TOKEN;

        $sql = "
            WITH input(raw, idx) AS (VALUES {$valuesList}),
            stem AS (
                SELECT raw, idx,
                       COALESCE((ts_lexize(:dict::regdictionary, raw))[1], lower(raw)) AS lex
                FROM input
            ),
            exact AS (
                SELECT s.raw, s.idx, s.lex, t.term, 1.0::float AS sim, t.df
                FROM stem s
                JOIN {$terms} t ON t.term = s.lex
            ),
            fuzzy AS (
                SELECT s.raw, s.idx, s.lex, t.term, word_similarity(s.lex, t.term) AS sim, t.df
                FROM stem s
                JOIN {$terms} t ON t.term % s.lex
                WHERE char_length(s.lex) >= {$minLen}
                  AND NOT EXISTS (SELECT 1 FROM exact e WHERE e.raw = s.raw)
                  AND word_similarity(s.lex, t.term) >= {$threshold}
                  {$fuzzyExtra}
            ),
            ranked AS (
                SELECT raw, idx, lex, term,
                       ROW_NUMBER() OVER (
                           PARTITION BY raw
                           ORDER BY sim DESC, df DESC
                       ) AS rn
                FROM (SELECT * FROM exact UNION ALL SELECT * FROM fuzzy) m
            )
            SELECT raw, lex, term FROM ranked WHERE rn <= {$maxPerToken}
            ORDER BY idx, rn
        ";

        $rows = SmartSearch::getInstance()->databaseService->fetchAll($sql, $params, 'lookupVariants');

        $results = [];
        foreach ($tokens as $token) {
            $results[$token] = ['lex' => mb_strtolower($token), 'variants' => []];
        }

        foreach ($rows as $row) {
            $raw = (string)$row['raw'];
            if (!isset($results[$raw])) {
                continue;
            }
            if ($row['lex'] !== null) {
                $results[$raw]['lex'] = (string)$row['lex'];
            }
            if ($row['term'] !== null) {
                $clean = self::cleanLexeme((string)$row['term']);
                if ($clean !== '' && !in_array($clean, $results[$raw]['variants'], true)) {
                    $results[$raw]['variants'][] = $clean;
                }
            }
        }

        return $this->variantCache[$cacheKey] = $results;
    }
}
