<?php

namespace ghoststreet\craftsmartsearch\controllers;

use Craft;
use craft\web\Controller;
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
}
