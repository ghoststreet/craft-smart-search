<?php

namespace ghoststreet\craftsmartsearch\assets;

use craft\web\AssetBundle;

/**
 * Asset bundle for the Smart Search dashboard page (template smart-search/index,
 * rendered by DashboardController::actionIndex).
 */
class DashboardAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [SmartSearchAsset::class, ChartAsset::class];
        $this->js = ['js/pages/dashboard.js'];
        $this->css = ['css/pages/dashboard.css'];
        parent::init();
    }
}
