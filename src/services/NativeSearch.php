<?php

namespace ghoststreet\craftsmartsearch\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\search\SearchQuery;
use craft\search\SearchQueryTerm;
use craft\search\SearchQueryTermGroup;
use craft\services\Search;
use craft\web\Request as WebRequest;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * Craft's `search` component with Smart Search ranking on top, so an existing site's
 * `craft.entries.search(q)` and GraphQL `entries(search:)` improve with no template
 * change, and behave as they did in every other way.
 *
 * Craft still finds its own matches, with its full syntax (`title:foo`, `-word`,
 * `"phrase"`, `word*`, `OR`), every filter on the query, every site, drafts and
 * statuses, and its own search events. Smart Search's results come first, in Smart
 * Search's order; Craft's remaining matches follow in Craft's order, so nothing Craft
 * would have found is lost and paging runs to the end. When the query uses syntax,
 * only Craft's matches are kept, since only Craft can honour it. If Smart Search
 * fails, the result is Craft's alone.
 *
 * Entry queries on front-end and GraphQL requests only; the control panel and other
 * element types are Craft's.
 */
class NativeSearch extends Search
{
    /** @var array<string, list<string>> Smart Search's keys per search, so `.count()` then `.all()` searches once. */
    private array $smartKeys = [];

    /**
     * Craft's scores replaced by rank: Smart Search's order first, then Craft's. Craft
     * sorts by these and exposes them as `searchScore`, higher first.
     */
    public function searchElements(ElementQuery $elementQuery): array
    {
        $native = parent::searchElements($elementQuery);
        if (!$this->handles($elementQuery)) {
            return $native;
        }

        /** @var EntryQuery $elementQuery */
        $smart = $this->smartKeysFor($elementQuery);
        $allowed = $this->hasSyntax($elementQuery->search) ? $native : $this->inScope($elementQuery, $smart);
        $smart = array_values(array_filter($smart, static fn(string $key): bool => isset($allowed[$key])));

        $keys = array_values(array_unique([...$smart, ...array_keys($native)]));
        $count = count($keys);

        $scores = [];
        foreach ($keys as $i => $key) {
            $scores[$key] = $count - $i;
        }

        return $scores;
    }

    /**
     * The Smart Search keys the query's own filters admit (section, type, status, dates,
     * relations, custom fields), keyed for lookup. Craft pages this list before it
     * applies those filters, so a key they reject would leave a hole in the page.
     * Scoped the way Craft scopes its own matches in searchElements().
     *
     * @param list<string> $keys
     * @return array<string, true>
     */
    private function inScope(EntryQuery $elementQuery, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $ids = (clone $elementQuery)
            ->select('elements.id')
            ->search(null)
            ->offset(null)
            ->limit(null)
            ->andWhere(['elements.id' => array_map(self::elementId(...), $keys)])
            ->column();
        $ids = array_flip(array_map('intval', $ids));

        $allowed = [];
        foreach ($keys as $key) {
            if (isset($ids[self::elementId($key)])) {
                $allowed[$key] = true;
            }
        }

        return $allowed;
    }

    /**
     * For queries not ordered by score Craft only asks which elements match: Craft's
     * matches, plus Smart Search's when the query is plain words.
     */
    public function createDbQuery(string|array|SearchQuery $searchQuery, ElementQuery $elementQuery): Query|false
    {
        $native = parent::createDbQuery($searchQuery, $elementQuery);
        if (!$this->handles($elementQuery) || $this->hasSyntax($elementQuery->search)) {
            return $native;
        }

        /** @var EntryQuery $elementQuery */
        $smart = array_map(self::elementId(...), $this->smartKeysFor($elementQuery));
        if ($smart === []) {
            return $native;
        }

        return $native === false
            ? (new Query())->from([Table::SEARCHINDEX])->where(['elementId' => $smart])->andFilterWhere(['siteId' => $elementQuery->siteId])
            : $native->orWhere(['elementId' => $smart]);
    }

    /**
     * Only the query that carries the search: Craft's own internal pass clones it with
     * `search` cleared, and that one must stay Craft's.
     */
    private function handles(ElementQuery $elementQuery): bool
    {
        if (!$elementQuery instanceof EntryQuery || !is_string($elementQuery->search) || trim($elementQuery->search) === '') {
            return false;
        }

        $request = Craft::$app->getRequest();

        return $request instanceof WebRequest && $request->getIsSiteRequest() && $elementQuery->siteId !== null;
    }

    /** Anything beyond bare words: only Craft knows what it means. */
    private function hasSyntax(string $search): bool
    {
        if (str_contains($search, '*')) {
            return true;
        }

        foreach ($this->normalizeSearchQuery($search)->getTokens() as $token) {
            if (!$token instanceof SearchQueryTerm || $token->attribute !== null || $token->exclude || $token->phrase) {
                return true;
            }
        }

        return false;
    }

    /** The words Smart Search ranks by: every term's text, excluded ones left out. */
    private function rankingText(string $search): string
    {
        $words = [];
        foreach ($this->normalizeSearchQuery($search)->getTokens() as $token) {
            foreach ($token instanceof SearchQueryTermGroup ? $token->terms : [$token] as $term) {
                if (!$term->exclude && $term->term !== '') {
                    $words[] = $term->term;
                }
            }
        }

        return implode(' ', $words);
    }

    /** @return list<string> "elementId-siteId", best first */
    private function smartKeysFor(EntryQuery $elementQuery): array
    {
        $text = $this->rankingText($elementQuery->search);
        $siteIds = array_map('intval', (array)$elementQuery->siteId);
        $sectionIds = $elementQuery->sectionId === null ? null : array_map('intval', (array)$elementQuery->sectionId);

        return $this->smartKeys[implode('|', [implode(',', $siteIds), $sectionIds === null ? '*' : implode(',', $sectionIds), $text])]
            ??= SmartSearch::getInstance()->searchRunner->native($text, $siteIds, $sectionIds);
    }

    /** The element id out of an "elementId-siteId" key. */
    private static function elementId(string $key): int
    {
        return (int)explode('-', $key, 2)[0];
    }
}
