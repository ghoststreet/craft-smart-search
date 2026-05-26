<?php

namespace ghoststreet\craftsmartsearch\services;

use craft\elements\Entry;
use ghoststreet\craftsmartsearch\SmartSearch;
use ghoststreet\craftsmartsearch\helpers\Logger;
use ghoststreet\craftsmartsearch\helpers\TimingProfiler;
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
    /**
     * Perform smart search combining semantic similarity and keyword scoring
     * using Reciprocal Rank Fusion (RRF) to merge both signal types.
     *
     * @throws \ghoststreet\craftsmartsearch\exceptions\EmbeddingException If embedding generation fails
     * @throws \ghoststreet\craftsmartsearch\exceptions\SearchException If vector or Keyword query fails
     */
    public function search(string $query, int $limit = 10, ?int $siteId = null, ?string $embeddingModel = null): array
    {
        $settings = SmartSearch::getInstance()->getSettings();

        $queryVector = TimingProfiler::profile(
            'Query embedding generation',
            fn() => SmartSearch::getInstance()->embeddingService->generateEmbedding($query, true, $embeddingModel)
        );

        $keywordResults = TimingProfiler::profile(
            'Keyword scoring',
            fn() => SmartSearch::getInstance()->keywordSearchService->calculateScores($query, $siteId)
        );

        $semanticResults = TimingProfiler::profile(
            'Vector similarity query',
            fn() => SmartSearch::getInstance()->searchService->semanticSearchRaw($query, min($settings->maxSemanticResults, $limit * 10), $siteId, $embeddingModel, $queryVector)
        );

        $semanticLookup = $this->buildSemanticLookup($semanticResults);
        $keywordLookup = $this->buildKeywordLookup($keywordResults);

        Logger::debug('Smart search signals', [
            'semanticRawRows' => count($semanticResults),
            'semanticUniqueElements' => count($semanticLookup),
            'keywordUniqueElements' => count($keywordLookup),
        ]);

        $allIds = array_unique([...array_keys($semanticLookup), ...array_keys($keywordLookup)]);

        Logger::debug('Smart search candidates', [
            'totalUniqueCandidates' => count($allIds),
        ]);

        $scoredResults = (new RrfFuser())->fuse(
            $semanticLookup,
            $keywordLookup,
            $settings->rrfSemanticWeight,
            $settings->rrfKeywordWeight,
            $settings->singleSignalPenalty,
            $settings->minSemanticThreshold,
        );

        Logger::debug('RRF signal breakdown', [
            'totalCandidates' => count($allIds),
            'survived' => count($scoredResults),
            'dropped' => count($allIds) - count($scoredResults),
            'minSemanticThreshold' => $settings->minSemanticThreshold,
            'singleSignalPenalty' => $settings->singleSignalPenalty,
            'rrfSemanticWeight' => $settings->rrfSemanticWeight,
            'rrfKeywordWeight' => $settings->rrfKeywordWeight,
        ]);

        Logger::debug('RRF scoring complete', [
            'survivedRRF' => count($scoredResults),
            'droppedByRRF' => count($allIds) - count($scoredResults),
        ]);

        uasort($scoredResults, fn($a, $b) => $b['rrfScore'] <=> $a['rrfScore']);

        $finalResults = $this->loadElementsWithScores($scoredResults, $limit);

        Logger::debug('Smart search final results', [
            'requestedLimit' => $limit,
            'returnedResults' => count($finalResults),
        ]);

        return $finalResults;
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
