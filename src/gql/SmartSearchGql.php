<?php

namespace ghoststreet\craftsmartsearch\gql;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\Entry as EntryInterface;
use craft\helpers\Gql as GqlHelper;
use ghoststreet\craftsmartsearch\exceptions\RateLimitException;
use ghoststreet\craftsmartsearch\exceptions\SmartSearchException;
use ghoststreet\craftsmartsearch\helpers\ApiResponseHelper;
use ghoststreet\craftsmartsearch\helpers\CacheTag;
use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\services\SearchRunner;
use ghoststreet\craftsmartsearch\SmartSearch;
use GraphQL\Error\UserError;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Smart Search's queries on Craft's own GraphQL endpoint.
 *
 * Results nest the real Entry through EntryInterface, so a headless caller gets
 * the score and excerpt plus any custom field in one round trip.
 *
 * Both queries are schema components, so an admin decides per token whether it
 * may search and whether it may spend AI credits.
 */
final class SmartSearchGql
{
    public const COMPONENT_SEARCH = 'smartsearch.search';
    public const COMPONENT_AI_ANSWER = 'smartsearch.aianswer';

    /** @return array<string, array<string, mixed>> */
    public static function queries(): array
    {
        return [
            'smartSearch' => [
                'type' => Type::listOf(self::resultType()),
                'description' => 'Semantic + keyword search, best first.',
                'args' => [
                    'query' => Type::nonNull(Type::string()),
                    'limit' => Type::int(),
                    'siteId' => Type::int(),
                    'sections' => Type::listOf(Type::string()),
                ],
                'resolve' => self::class . '::resolveSearch',
            ],
            'smartSearchAiAnswer' => [
                'type' => self::aiAnswerType(),
                'description' => 'AI-written answer with the entries it drew on.',
                'args' => [
                    'query' => Type::nonNull(Type::string()),
                    'limit' => Type::int(),
                    'siteId' => Type::int(),
                ],
                'resolve' => self::class . '::resolveAiAnswer',
            ],
        ];
    }

    /** @return array<string, array<string, string>> */
    public static function schemaComponents(): array
    {
        return [
            self::COMPONENT_SEARCH . ':read' => [
                'label' => Craft::t('smart-search', 'Run Smart Search queries'),
            ],
            self::COMPONENT_AI_ANSWER . ':read' => [
                'label' => Craft::t('smart-search', 'Run AI Answer queries (spends AI credits)'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<int, array<string, mixed>>
     */
    public static function resolveSearch(mixed $source, array $arguments): array
    {
        self::requireComponent(self::COMPONENT_SEARCH);
        CacheTag::collectResults();

        $allowed = self::schemaSectionIds();
        $requested = RequestParameterExtractor::normalizeSections($arguments['sections'] ?? null);
        $sectionIds = $requested === []
            ? $allowed
            : array_values(array_intersect(RequestParameterExtractor::sectionIds($requested), $allowed));

        if ($sectionIds === []) {
            return [];
        }

        return self::guard(static fn(): array => SmartSearch::getInstance()->searchRunner->search(
            (string)$arguments['query'],
            (int)($arguments['limit'] ?? SearchRunner::DEFAULT_SEARCH_LIMIT),
            self::siteId($arguments['siteId'] ?? null),
            $sectionIds,
        ), 'gqlSearch');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function resolveAiAnswer(mixed $source, array $arguments): array
    {
        self::requireComponent(self::COMPONENT_AI_ANSWER);
        CacheTag::collectResults();

        $sectionIds = self::schemaSectionIds();

        if ($sectionIds === []) {
            return SearchRunner::EMPTY_ANSWER;
        }

        return self::guard(static fn(): array => SmartSearch::getInstance()->searchRunner->aiAnswer(
            (string)$arguments['query'],
            (int)($arguments['limit'] ?? SearchRunner::DEFAULT_ANSWER_LIMIT),
            self::siteId($arguments['siteId'] ?? null),
            $sectionIds,
        ), 'gqlAiAnswer');
    }

    /**
     * Sections the active schema may read. Search returns elements directly, so it
     * never passes through the scope check Craft applies inside element queries;
     * without this, one token's results would include another's sections. An empty
     * result means the token may read nothing, which is not the same as "no filter".
     *
     * @return list<int>
     */
    private static function schemaSectionIds(): array
    {
        $uids = GqlHelper::extractAllowedEntitiesFromSchema('read')['sections'] ?? [];

        return array_values(array_filter(array_map(
            static fn(string $uid): ?int => Craft::$app->getEntries()->getSectionByUid($uid)?->id,
            $uids,
        )));
    }

    private static function requireComponent(string $component): void
    {
        if (!GqlHelper::canSchema($component)) {
            throw new UserError(Craft::t('smart-search', 'This token is not allowed to run that query.'));
        }
    }

    /**
     * Run a search, translating the plugin's exceptions into client-visible GraphQL
     * errors. Only the curated message crosses the wire; anything else bubbles up
     * for Craft to log and report generically.
     */
    private static function guard(callable $work, string $operation): array
    {
        try {
            return $work();
        } catch (RateLimitException $e) {
            throw new UserError(Craft::t('smart-search', 'Rate limit reached. Try again in {seconds} seconds.', [
                'seconds' => $e->retryAfterSeconds,
            ]));
        } catch (SmartSearchException $e) {
            throw new UserError(ApiResponseHelper::present($e, $operation));
        }
    }

    /** No siteId means the current site; otherwise the REST API's rules apply. */
    private static function siteId(mixed $raw): ?int
    {
        if ($raw === null) {
            return Craft::$app->getSites()->getCurrentSite()->id;
        }

        [$siteId, $valid] = RequestParameterExtractor::resolveSiteId($raw);

        if (!$valid) {
            throw new UserError(Craft::t('smart-search', 'Unknown siteId.'));
        }

        return $siteId;
    }

    private static function resultType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SmartSearchResult', static fn(): ObjectType => new ObjectType([
            'name' => 'SmartSearchResult',
            'description' => 'One search hit: how it scored, and the entry it points at.',
            'fields' => [
                'entry' => [
                    'type' => EntryInterface::getType(),
                    'resolve' => static fn(array $row) => $row['element'],
                ],
                'title' => Type::string(),
                'url' => Type::string(),
                'excerpt' => Type::string(),
                'sectionHandle' => Type::string(),
                'score' => Type::float(),
                'semanticScore' => Type::float(),
                'keywordScore' => Type::float(),
                'semanticRank' => Type::int(),
                'keywordRank' => Type::int(),
                'smartRank' => Type::int(),
            ],
        ]));
    }

    private static function aiAnswerType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SmartSearchAiAnswer', static fn(): ObjectType => new ObjectType([
            'name' => 'SmartSearchAiAnswer',
            'description' => 'An AI-written answer and the entries behind it.',
            'fields' => [
                'summary' => Type::string(),
                'confidence' => Type::string(),
                'budgetExhausted' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'True when the daily AI budget is spent: sources are returned without a summary.',
                ],
                'sources' => Type::listOf(self::sourceType()),
            ],
        ]));
    }

    private static function sourceType(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SmartSearchAiSource', static fn(): ObjectType => new ObjectType([
            'name' => 'SmartSearchAiSource',
            'description' => 'An entry the AI answer drew on.',
            'fields' => [
                'entry' => [
                    'type' => EntryInterface::getType(),
                    'resolve' => static fn(array $row) => $row['element'],
                ],
                'title' => Type::string(),
                'url' => Type::string(),
                'sectionHandle' => Type::string(),
                'rank' => Type::int(),
            ],
        ]));
    }
}
