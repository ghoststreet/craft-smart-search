<?php

namespace ghoststreet\craftsmartsearch\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Base CP asset bundle for the Craft Search plugin.
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
            'js/smart-search-config.js',
            'js/core/dom.js',
            'js/core/errors.js',
            'js/core/api.js',
            'js/core/utils.js',
            'js/components/filter-bar.js',
        ];
        $this->css = [
            // Base (must load first)
            'css/base/smart-search.css',

            // Components (alphabetical)
            'css/components/alert.css',
            'css/components/badge.css',
            'css/components/budget-bar.css',
            'css/components/chunk-row.css',
            'css/components/data-table.css',
            'css/components/debug-extracted.css',
            'css/components/delta-tag.css',
            'css/components/empty-state.css',
            'css/components/entry-status-pill.css',
            'css/components/filter-bar.css',
            'css/components/health-row.css',
            'css/components/index-stats.css',
            'css/components/kpi-grid.css',
            'css/components/onboarding-list.css',
            'css/components/overview-cards.css',
            'css/components/pagination.css',
            'css/components/recommendation-list.css',
            'css/components/search-toolbar.css',
            'css/components/sync-progress.css',

            // Pages (alphabetical)
            'css/pages/dashboard.css',
            'css/pages/debug-entry.css',
            'css/pages/history-detail.css',
            'css/pages/history-index.css',
            'css/pages/history-keywords.css',
            'css/pages/insights-index.css',
            'css/pages/settings.css',
        ];

        parent::init();
    }
}
