<?php

namespace ghoststreet\craftsmartsearch\helpers;

use craft\elements\Entry;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Formats search-result entries into the API payload, with per-type field
 * additions (excerpt for Craft, scores for semantic/smart, AI rank for AI Answer).
 */
final class SearchResultFormatter
{
    public const TYPE_CRAFT = 'craft';
    public const TYPE_SEMANTIC = 'semantic';
    public const TYPE_SMART = 'smart';
    public const TYPE_AI_ANSWER = 'aiAnswer';

    /**
     * Format a search result for API response.
     *
     * @param Entry $element The entry element
     * @param array $metadata Additional result data (scores, ranks, content, etc.)
     * @param string $type Result type: craft, semantic, smart, or aiAnswer
     * @return array|null Null if element has no URL
     */
    public static function format(Entry $element, array $metadata, string $type): ?array
    {
        $url = $element->getUrl();
        if ($url === null) {
            return null;
        }

        $result = [
            'id' => $element->id,
            'title' => $element->title,
            'url' => $url,
            'type' => $type,
        ];

        return match ($type) {
            self::TYPE_CRAFT => self::addCraftFields($result, $metadata),
            self::TYPE_SEMANTIC => self::addSemanticFields($result, $metadata),
            self::TYPE_SMART => self::addSmartFields($result, $metadata),
            self::TYPE_AI_ANSWER => self::addAiAnswerFields($result, $metadata),
            default => $result,
        };
    }

    /**
     * Get excerpt from content string, optionally skipping the title if it appears at the start.
     */
    public static function getExcerptFromContent(string $content, ?string $title = null): string
    {
        if (empty($content)) {
            return '';
        }

        $settings = SmartSearch::getInstance()->getSettings();
        $excerptLength = $settings->excerptLength;

        $content = strip_tags(trim($content));

        if ($title !== null && str_starts_with($content, $title)) {
            $content = trim(substr($content, strlen($title)));
        }

        if (empty($content)) {
            return '';
        }

        $excerpt = mb_substr($content, 0, $excerptLength);
        if (mb_strlen($content) > $excerptLength) {
            $excerpt .= '...';
        }

        return $excerpt;
    }

    /** Craft-search payload: just an excerpt (native search returns rank by position, no score). */
    private static function addCraftFields(array $result, array $metadata): array
    {
        $result['excerpt'] = $metadata['excerpt'] ?? '';
        return $result;
    }

    /** Semantic-only payload: score + excerpt. semanticScore mirrors score when not separated. */
    private static function addSemanticFields(array $result, array $metadata): array
    {
        $result['score'] = round($metadata['score'], 4);
        $result['semanticScore'] = round($metadata['semanticScore'] ?? $metadata['score'], 4);
        $result['excerpt'] = $metadata['excerpt'];
        return $result;
    }

    /** Hybrid payload: fused RRF score + component scores/ranks (each optional). */
    private static function addSmartFields(array $result, array $metadata): array
    {
        $result['score'] = round($metadata['score'], 4);
        $result['excerpt'] = $metadata['excerpt'];

        $roundedFields = ['semanticScore' => 4, 'keywordScore' => 4];
        foreach ($roundedFields as $field => $precision) {
            if (isset($metadata[$field])) {
                $result[$field] = round($metadata[$field], $precision);
            }
        }

        $passthroughFields = ['semanticRank', 'keywordRank', 'smartRank'];
        foreach ($passthroughFields as $field) {
            if (isset($metadata[$field])) {
                $result[$field] = $metadata[$field];
            }
        }

        return $result;
    }

    /** AI Answer payload: just the LLM-assigned rank (no numeric scores). */
    private static function addAiAnswerFields(array $result, array $metadata): array
    {
        $result['rank'] = $metadata['ragRank'] ?? null;
        return $result;
    }
}
