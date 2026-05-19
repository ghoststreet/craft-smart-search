<?php

namespace ghoststreet\craftaisearch\services;

use craft\elements\Entry;
use ghoststreet\craftaisearch\AiSearch;
use ghoststreet\craftaisearch\helpers\Logger;
use ghoststreet\craftaisearch\helpers\TimingProfiler;
use yii\base\Component;

/**
 * Hybrid Search Service — combines semantic vector similarity and BM25 keyword scoring
 * using Reciprocal Rank Fusion (RRF) to produce a single ranked result list.
 *
 * Each signal contributes a weighted RRF score; results appearing in only one signal
 * receive a configurable penalty. Semantic-only results below a minimum threshold
 * are excluded entirely.
 */
class HybridSearchService extends Component
{
    /**
     * Perform hybrid search combining semantic similarity and BM25 keyword scoring
     * using Reciprocal Rank Fusion (RRF) to merge both signal types.
     *
     * @throws \ghoststreet\craftaisearch\exceptions\EmbeddingException If embedding generation fails
     * @throws \ghoststreet\craftaisearch\exceptions\SearchException If vector or BM25 query fails
     */
    public function search(string $query, int $limit = 10, ?int $siteId = null, ?string $embeddingModel = null): array
    {
        $settings = AiSearch::getInstance()->getSettings();

        $queryVector = TimingProfiler::profile(
            'Query embedding generation',
            fn() => AiSearch::getInstance()->embeddingService->generateEmbedding($query, true, $embeddingModel)
        );

        $bm25Results = TimingProfiler::profile(
            'BM25 scoring',
            fn() => AiSearch::getInstance()->bm25Service->calculateScores($query, $siteId)
        );

        $semanticResults = TimingProfiler::profile(
            'Vector similarity query',
            fn() => AiSearch::getInstance()->searchService->semanticSearchRaw($query, min($settings->maxSemanticResults, $limit * 10), $siteId, false, $embeddingModel, $queryVector)
        );

        $semanticLookup = $this->buildSemanticLookup($semanticResults);
        $bm25Lookup = $this->buildBM25Lookup($bm25Results);

        Logger::debug('Hybrid search signals', [
            'semanticRawRows' => count($semanticResults),
            'semanticUniqueElements' => count($semanticLookup),
            'bm25UniqueElements' => count($bm25Lookup),
        ]);

        $allIds = array_unique([...array_keys($semanticLookup), ...array_keys($bm25Lookup)]);

        Logger::debug('Hybrid search candidates', [
            'totalUniqueCandidates' => count($allIds),
        ]);

        $scoredResults = (new RrfFuser())->fuse(
            $semanticLookup,
            $bm25Lookup,
            $settings->rrfK,
            $settings->rrfSemanticWeight,
            $settings->rrfBm25Weight,
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
            'rrfBm25Weight' => $settings->rrfBm25Weight,
        ]);

        Logger::debug('RRF scoring complete', [
            'survivedRRF' => count($scoredResults),
            'droppedByRRF' => count($allIds) - count($scoredResults),
        ]);

        uasort($scoredResults, fn($a, $b) => $b['rrfScore'] <=> $a['rrfScore']);

        $finalResults = $this->loadElementsWithScores($scoredResults, $limit);

        Logger::debug('Hybrid search final results', [
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
     * Index BM25 results by elementId with rank and score for RRF lookup.
     */
    private function buildBM25Lookup(array $bm25Results): array
    {
        $lookup = [];
        $rank = 1;

        foreach ($bm25Results as $score) {
            if ($score['bm25Score'] > 0) {
                $lookup[$score['elementId']] = [
                    'score' => $score['bm25Score'],
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
                'bm25Score' => $data['bm25Score'],
                'bm25Rank' => $data['bm25Rank'],
                'hybridRank' => count($results) + 1,
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
