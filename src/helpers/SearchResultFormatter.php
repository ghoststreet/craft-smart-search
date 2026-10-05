<?php

namespace ghoststreet\craftsmartsearch\helpers;

use craft\elements\Entry;
use ghoststreet\craftsmartsearch\enums\SearchType;
use ghoststreet\craftsmartsearch\events\FormatSearchResultEvent;
use ghoststreet\craftsmartsearch\SmartSearch;
use Throwable;
use yii\helpers\StringHelper;

/**
 * Formats search-result entries into the API payload, with per-type field
 * additions (scores for smart, AI rank for AI Answer).
 */
final class SearchResultFormatter
{
    /**
     * Format a run of service rows, deriving each excerpt from the matched chunk. Every row
     * has a URL: Ranker::loadElements() drops the entries without one.
     *
     * @param array<int, array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    public static function formatMany(array $results, SearchType $type): array
    {
        return array_values(array_map(
            static fn(array $result): array => self::format(
                $result['element'],
                $result + [
                    'excerpt' => self::getExcerptFromContent($result['content'], $result['element']),
                ],
                $type
            ),
            $results
        ));
    }

    /**
     * Format a search result for API response.
     *
     * @param Entry $element The entry element
     * @param array $metadata Additional result data (scores, ranks, content, etc.)
     */
    private static function format(Entry $element, array $metadata, SearchType $type): array
    {
        $result = [
            'id' => $element->id,
            'title' => $element->title,
            'url' => $metadata['url'],
            'type' => $type->value,
            'sectionHandle' => $element->getSection()?->handle,
        ];

        $formatted = $type->isAiAnswer()
            ? self::addAiAnswerFields($result, $metadata)
            : self::addSmartFields($result, $metadata);

        return self::applyListenerFields($element, $type->value, $formatted);
    }

    /**
     * Let EVENT_FORMAT_RESULT listeners project field data into the payload.
     * A throwing listener is logged and swallowed so search keeps working;
     * the result is then returned without a `fields` key.
     */
    private static function applyListenerFields(Entry $element, string $type, array $result): array
    {
        $plugin = SmartSearch::getInstance();

        if (!$plugin->hasEventHandlers(SmartSearch::EVENT_FORMAT_RESULT)) {
            return $result;
        }

        try {
            $event = new FormatSearchResultEvent([
                'element' => $element,
                'type' => $type,
            ]);
            $plugin->trigger(SmartSearch::EVENT_FORMAT_RESULT, $event);

            if ($event->fields !== []) {
                $result['fields'] = $event->fields;
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'formatSearchResult event', ['elementId' => $element->id]);
        }

        return $result;
    }

    /**
     * Get excerpt from content string, skipping the title if it appears at the start
     * and the field prefixes, which are there for the embedding, not the reader.
     */
    private static function getExcerptFromContent(string $content, Entry $element): string
    {
        if (empty($content)) {
            return '';
        }

        $plugin = SmartSearch::getInstance();
        $excerptLength = $plugin->getSettings()->excerptLength;

        $content = strip_tags(trim(FieldPrefix::forDisplay($content)));

        $title = $element->title;
        if ($title !== null && str_starts_with($content, $title)) {
            $content = trim(substr($content, strlen($title)));
        }

        if (empty($content)) {
            return '';
        }

        return StringHelper::truncate($content, $excerptLength);
    }

    /** Hybrid payload: fused RRF score + component scores/ranks (each optional). */
    private static function addSmartFields(array $result, array $metadata): array
    {
        $result['score'] = round($metadata['score'], 4);
        $result['excerpt'] = $metadata['excerpt'];

        foreach (['semanticScore', 'keywordScore'] as $field) {
            if (isset($metadata[$field])) {
                $result[$field] = round($metadata[$field], 4);
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

    /** AI Answer payload: the LLM-assigned rank and excerpt (no numeric scores). */
    private static function addAiAnswerFields(array $result, array $metadata): array
    {
        $result['rank'] = $metadata['aiAnswerRank'] ?? null;
        $result['excerpt'] = $metadata['excerpt'];
        return $result;
    }
}
