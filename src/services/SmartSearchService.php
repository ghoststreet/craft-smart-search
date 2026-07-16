<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\elements\Entry;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\base\Component;

/**
 * Smart Search Service — combines semantic vector similarity and keyword scoring
 * using Reciprocal Rank Fusion (RRF) to produce a single ranked result list.
 *
 * Each signal contributes a weighted RRF score; results appearing in only one signal
 * receive a configurable penalty. Semantic-only results below a minimum threshold
 * are excluded entirely.
 */
class SmartSearchService extends Component
{
    public const EVENT_FORMAT_RESULT = 'formatSearchResult';

    /**
     * Perform smart search combining semantic similarity and keyword scoring
     * using Reciprocal Rank Fusion (RRF) to merge both signal types.
     *
     * @param string[]|null $sections Restrict results to these section handles
     * @throws \ghoststreet\craftsmartsearch\exceptions\EmbeddingException If embedding generation fails
     * @throws \ghoststreet\craftsmartsearch\exceptions\SearchException If vector or Keyword query fails
     */
    public function search(string $query, int $limit = 10, ?int $siteId = null, ?string $embeddingModel = null, ?array $sections = null): array
    {
        $settings = SmartSearch::getInstance()->getSettings();

        $sectionIds = null;
        if (!empty($sections)) {
            $sectionIds = $this->resolveSectionIds($sections);
            if ($sectionIds === []) {
                Logger::debug('Section filter matched no known sections', ['sections' => $sections]);
                return [];
            }
        }

        $queryVector = TimingProfiler::profile(
            'Query embedding generation',
            fn() => SmartSearch::getInstance()->embeddingService->generateEmbedding($query, true, $embeddingModel)
        );

        $keywordResults = TimingProfiler::profile(
            'Keyword scoring',
            fn() => SmartSearch::getInstance()->keywordSearchService->calculateScores($query, $siteId, $sectionIds)
        );

        $semanticResults = TimingProfiler::profile(
            'Vector similarity query',
            fn() => SmartSearch::getInstance()->searchService->semanticSearchRaw($query, min($settings->maxSemanticResults, $limit * 10), $siteId, $embeddingModel, $queryVector, $sectionIds)
        );

        $semanticLookup = $this->buildSemanticLookup($semanticResults);
        $keywordLookup = $this->buildKeywordLookup($keywordResults);

        $scoredResults = (new RrfFuser())->fuse(
            $semanticLookup,
            $keywordLookup,
            $settings->rrfSemanticWeight,
            $settings->rrfKeywordWeight,
            $settings->minSemanticThreshold,
        );

        Logger::debug('Smart search RRF', [
            'semanticRawRows' => count($semanticResults),
            'semanticUniqueElements' => count($semanticLookup),
            'keywordUniqueElements' => count($keywordLookup),
            'survived' => count($scoredResults),
            'minSemanticThreshold' => $settings->minSemanticThreshold,
            'rrfSemanticWeight' => $settings->rrfSemanticWeight,
            'rrfKeywordWeight' => $settings->rrfKeywordWeight,
        ]);

        $boosts = SmartSearch::getInstance()->boostService->match($query, $siteId);
        foreach ($boosts as $elementId => $weight) {
            if (isset($scoredResults[$elementId])) {
                $scoredResults[$elementId]['rrfScore'] += $weight;
            } else {
                $scoredResults[$elementId] = [
                    'rrfScore' => $weight,
                    'semanticScore' => 0.0, 'semanticRank' => 0,
                    'keywordScore' => 0.0, 'keywordRank' => 0,
                    'content' => '',
                ];
            }
        }

        uasort($scoredResults, fn($a, $b) => $b['rrfScore'] <=> $a['rrfScore']);

        $finalResults = $this->loadElementsWithScores($scoredResults, $limit);

        Logger::debug('Smart search final results', [
            'requestedLimit' => $limit,
            'returnedResults' => count($finalResults),
        ]);

        return $finalResults;
    }

    /**
     * Resolve section handles to ids. Unknown handles are dropped, so an
     * empty return means the filter cannot match anything.
     *
     * @param string[] $handles
     * @return int[]
     */
    private function resolveSectionIds(array $handles): array
    {
        $entries = Craft::$app->getEntries();
        $ids = [];

        foreach ($handles as $handle) {
            $section = $entries->getSectionByHandle($handle);
            if ($section !== null) {
                $ids[] = (int)$section->id;
            }
        }

        return $ids;
    }

    /**
     * Index semantic results by elementId with rank and score for RRF lookup.
     */
    private function buildSemanticLookup(array $semanticResults): array
    {
        $lookup = [];
        $rank = 1;

        foreach ($semanticResults as $result) {
            $elementId = (int)$result['elementId'];
            $lookup[$elementId] = [
                'score' => (float)$result['similarity'],
                'rank' => $rank++,
                'content' => $result['content'],
            ];
        }

        return $lookup;
    }

    /**
     * Index Keyword results by elementId with rank and score for RRF lookup.
     */
    private function buildKeywordLookup(array $keywordResults): array
    {
        $lookup = [];
        $rank = 1;

        foreach ($keywordResults as $score) {
            if ($score['keywordScore'] > 0) {
                $lookup[$score['elementId']] = [
                    'score' => $score['keywordScore'],
                    'rank' => $rank++,
                    'content' => $score['content'] ?? '',
                ];
            }
        }

        return $lookup;
    }

    /**
     * Load Craft Entry elements for the top-scored results and build the
     * final response array with all score dimensions attached.
     */
    private function loadElementsWithScores(array $scoredResults, int $limit): array
    {
        $allIds = array_keys($scoredResults);
        $elements = Entry::find()->id($allIds)->indexBy('id')->all();

        $missingCount = 0;
        $noUrlCount = 0;
        $results = [];

        foreach ($allIds as $id) {
            if (!isset($elements[$id])) {
                $missingCount++;
                continue;
            }

            $element = $elements[$id];
            if ($element->getUrl() === null) {
                $noUrlCount++;
                continue;
            }

            $data = $scoredResults[$id];

            $results[] = [
                'element' => $element,
                'score' => $data['rrfScore'],
                'semanticScore' => $data['semanticScore'],
                'semanticRank' => $data['semanticRank'],
                'keywordScore' => $data['keywordScore'],
                'keywordRank' => $data['keywordRank'],
                'smartRank' => count($results) + 1,
                'content' => $data['content'],
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        Logger::debug('loadElementsWithScores filtering', [
            'scoredCandidates' => count($allIds),
            'elementsLoadedFromCraft' => count($elements),
            'missingInCraft' => $missingCount,
            'noUrl' => $noUrlCount,
            'finalResults' => count($results),
            'limit' => $limit,
        ]);

        return $results;
    }
}
