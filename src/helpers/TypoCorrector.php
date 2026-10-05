<?php

namespace ghoststreet\craftsmartsearch\helpers;

/**
 * Picking a dictionary term for a word the corpus does not contain.
 *
 * Candidate rows are fetched by whoever owns the dictionary; the rule for choosing between
 * them lives only here.
 *
 * ponytail: plain Levenshtein, so a transposition costs two edits and is therefore not
 * corrected in a word under 8 bytes. Damerau-Levenshtein scores a swap as one and is the
 * upgrade if swaps prove to be the common typo; PHP has no built-in for it.
 *
 * ponytail: levenshtein() counts bytes, so the dictionary stores byte lengths to match and
 * distances are character-accurate only for single-byte scripts. A multibyte implementation is
 * the upgrade if a non-Latin corpus needs it.
 */
final class TypoCorrector
{
    /** Below this length a correction is more likely to be wrong than right. */
    public const MIN_LENGTH = 4;

    /** Ceiling on candidates a caller should fetch for one lexeme's length band. */
    public const CANDIDATE_LIMIT = 20000;

    public static function isCorrectable(string $lexeme): bool
    {
        return strlen($lexeme) >= self::MIN_LENGTH;
    }

    /** Edit distance allowed for a lexeme of this length. The Algolia-style rule. */
    public static function ceiling(string $lexeme): int
    {
        return strlen($lexeme) >= 8 ? 2 : 1;
    }

    /**
     * The best correction among candidates, or null when none is close enough.
     *
     * Ties break on document frequency: the commoner word is the likelier intent.
     *
     * @param array<array{term: string|int, df: int|string}> $candidates
     */
    public static function best(string $lexeme, array $candidates): ?string
    {
        $ceiling = self::ceiling($lexeme);
        $length = strlen($lexeme);

        $bestTerm = null;
        $bestDf = -1;

        foreach ($candidates as $candidate) {
            $term = (string)$candidate['term'];

            if (abs(strlen($term) - $length) > $ceiling) {
                continue;
            }
            if (levenshtein($term, $lexeme) > $ceiling) {
                continue;
            }

            $df = (int)$candidate['df'];
            if ($df > $bestDf) {
                $bestDf = $df;
                $bestTerm = $term;
            }
        }

        return $bestTerm;
    }
}
