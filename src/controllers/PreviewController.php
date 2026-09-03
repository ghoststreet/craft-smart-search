<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use ghoststreet\craftsmartsearch\helpers\RequestParameterExtractor;
use ghoststreet\craftsmartsearch\SmartSearch;
use yii\web\Response;

class PreviewController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $plugin = SmartSearch::getInstance();
        $sites = Craft::$app->getSites()->getAllSites();

        return $this->renderTemplate('smart-search/preview/index', [
            'plugin' => $plugin,
            'sites' => $sites,
            'selectedSubnavItem' => 'preview',
            'wikiUrl' => SmartSearch::WIKI_URL,
        ]);
    }

    /**
     * Craft's native keyword search, used as the Preview page's baseline column.
     * Admin-only on purpose: this is a comparison, not a plugin search mode, so it
     * stays off the public API and out of search history.
     */
    public function actionCraftSearch(): Response
    {
        $this->requireAdmin();
        $this->requireAcceptsJson();

        $params = RequestParameterExtractor::extractSearchParams();

        if ($params['validationError'] !== null) {
            return $this->asJson($params['validationError'])->setStatusCode(400);
        }

        $query = Entry::find()
            ->status(Entry::STATUS_ENABLED)
            ->search($params['query'])
            ->limit($params['limit']);

        if ($params['siteId'] !== null) {
            $query->siteId($params['siteId']);
        }

        $results = array_values(array_filter(array_map(
            static function(Entry $entry): ?array {
                $url = $entry->getUrl();

                return $url === null ? null : [
                    'id' => $entry->id,
                    'title' => $entry->title,
                    'url' => $url,
                ];
            },
            $query->all()
        )));

        return $this->asJson([
            'success' => true,
            'results' => $results,
        ]);
    }
}
