<?php

namespace ghoststreet\craftsmartsearch\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Base CP asset bundle for the Smart Search plugin.
 * Bootstraps the window.SmartSearch namespace and shared core modules.
 * Page-specific bundles depend on this and add their own components/pages.
 */
class SmartSearchAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = [
            'js/smart-search-base.js',
            'js/core/dom.js',
            'js/components/filter-bar.js',
            'js/components/error-hud.js',
        ];
        $this->css = [
            'css/base/smart-search.css',
            'css/components/bar.css',
            'css/components/data-table.css',
            'css/components/empty-state.css',
            'css/components/filter-bar.css',
            'css/components/index-stats.css',
        ];

        parent::init();
    }
}
