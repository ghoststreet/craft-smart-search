<?php

namespace ghoststreet\craftsmartsearch\helpers;

use craft\elements\Entry;
use ghoststreet\craftsmartsearch\events\FormatSearchResultEvent;
use ghoststreet\craftsmartsearch\services\SmartSearchService;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;

/**
 * Formats search-result entries into the API payload, with per-type field
 * additions (scores for semantic/smart, AI rank for AI Answer).
 */
final class SearchResultFormatter
{
    public const TYPE_SEMANTIC = 'semantic';
    public const TYPE_SMART = 'smart';
    public const TYPE_AI_ANSWER = 'aiAnswer';

    /**
     * Format a search result for API response.
     *
     * @param Entry $element The entry element
     * @param array $metadata Additional result data (scores, ranks, content, etc.)
     * @param string $type Result type: semantic, smart, or aiAnswer
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
            'sectionHandle' => $element->getSection()?->handle,
        ];

        $formatted = match ($type) {
            self::TYPE_SEMANTIC => self::addSemanticFields($result, $metadata),
            self::TYPE_SMART => self::addSmartFields($result, $metadata),
            self::TYPE_AI_ANSWER => self::addAiAnswerFields($result, $metadata),
            default => $result,
        };

        return self::applyListenerFields($element, $type, $formatted);
    }

    /**
     * Let EVENT_FORMAT_RESULT listeners project field data into the payload.
     * A throwing listener is logged and swallowed so search keeps working;
     * the result is then returned without a `fields` key.
     */
    private static function applyListenerFields(Entry $element, string $type, array $result): array
    {
        $service = SmartSearch::getInstance()->smartSearchService;

        if (!$service->hasEventHandlers(SmartSearchService::EVENT_FORMAT_RESULT)) {
            return $result;
        }

        try {
            $event = new FormatSearchResultEvent([
                'element' => $element,
                'type' => $type,
            ]);
            $service->trigger(SmartSearchService::EVENT_FORMAT_RESULT, $event);

            if ($event->fields !== []) {
                $result['fields'] = $event->fields;
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'formatSearchResult event', ['elementId' => $element->id]);
        }

        return $result;
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
