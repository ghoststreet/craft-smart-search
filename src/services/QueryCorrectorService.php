<?php

namespace ghoststreet\craftsmartsearch\services;

use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\SmartSearch;
use PDO;
use PDOException;
use yii\base\Component;

/**
 * Builds a typo-tolerant tsquery expression by looking each query token up in
 * the corpus dictionary via trigram similarity (pg_trgm).
 *
 * Always returns null on any unavailability or failure — KeywordSearchService then uses
 * its existing websearch_to_tsquery path verbatim. The contract is "best-effort
 * enhancement, never a regression."
 */
class QueryCorrectorService extends Component
{
    /** Algolia-style edit-distance ceiling — under this length, no correction. */
    private const MIN_TOKEN_LENGTH = 4;

    /** Max corrections fetched per token. */
    private const MAX_VARIANTS_PER_TOKEN = 3;

    /** word_similarity threshold; pg_trgm default is 0.6, we relax to 0.4 for short typos. */
    private const SIMILARITY_THRESHOLD = 0.4;

    /**
     * Returns a tsquery expression suitable for `to_tsquery(:config, $expression)`,
     * or null if correction is unavailable / not applicable.
     *
     * The expression keeps original tokens AND OR's in dictionary-matched
     * variants: e.g. `aple` → `(aple | apple) & pie`.
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

        try {
            $variants = $this->lookupVariants($tokens, $dictionary);
        } catch (PDOException $e) {
            Logger::warning('Typo correction lookup failed; falling back to exact keyword search', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $anyCorrected = false;
        $parts = [];
        foreach ($tokens as $token) {
            $candidates = [$token];
            foreach ($variants[$token] ?? [] as $variant) {
                if ($variant !== $token && !in_array($variant, $candidates, true)) {
                    $candidates[] = $variant;
                    $anyCorrected = true;
                }
            }
            $parts[] = count($candidates) === 1
                ? $candidates[0]
                : '(' . implode(' | ', $candidates) . ')';
        }

        if (!$anyCorrected) {
            return null;
        }

        return implode(' & ', $parts);
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
     * Strip everything that to_tsquery would reject; lexemes are alnum + underscore.
     */
    private static function cleanLexeme(string $word): string
    {
        return preg_replace('/[^\p{L}\p{N}_]+/u', '', $word) ?? '';
    }

    /**
     * Single round-trip lookup for all tokens. Each candidate is gated by
     * word_similarity threshold and (when fuzzystrmatch is available) a
     * length-based Levenshtein cap to reject distant-but-similar trigrams.
     *
     * @param string[] $tokens
     * @return array<string, string[]> Map of original token -> candidate variants (ordered best-first).
     */
    private function lookupVariants(array $tokens, DictionaryService $dictionary): array
    {
        $db = SmartSearch::getInstance()->databaseService->getConnection();
        $terms = $dictionary->qualifiedTermsTable();
        $hasFuzzy = $dictionary->hasFuzzyStrMatch();

        $results = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < self::MIN_TOKEN_LENGTH) {
                $results[$token] = [];
                continue;
            }

            $maxEdits = mb_strlen($token) >= 8 ? 2 : 1;

            $sql = "
                SELECT term
                FROM {$terms}
                WHERE term % :token
                  AND word_similarity(:token, term) >= :threshold
            ";
            $params = [
                ':token' => $token,
                ':threshold' => self::SIMILARITY_THRESHOLD,
            ];

            if ($hasFuzzy) {
                $sql .= " AND levenshtein_less_equal(term, :token2, :maxEdits) <= :maxEdits2";
                $params[':token2'] = $token;
                $params[':maxEdits'] = $maxEdits;
                $params[':maxEdits2'] = $maxEdits;
            }

            $sql .= "
                ORDER BY word_similarity(:tokenOrder, term) DESC, df DESC
                LIMIT :limit
            ";
            $params[':tokenOrder'] = $token;

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            $stmt->bindValue(':limit', self::MAX_VARIANTS_PER_TOKEN, PDO::PARAM_INT);
            $stmt->execute();

            $variants = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $clean = self::cleanLexeme((string)$row['term']);
                if ($clean !== '') {
                    $variants[] = $clean;
                }
            }
            $results[$token] = $variants;
        }

        return $results;
    }
}
