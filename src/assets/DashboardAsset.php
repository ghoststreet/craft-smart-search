<?php

namespace ghoststreet\craftsmartsearch\assets;

/**
 * Asset bundle for the Smart Search dashboard page (template smart-search/index,
 * rendered by DashboardController::actionIndex).
 */
class DashboardAsset extends PageAsset
{
    public $depends = [ChartAsset::class];
    public $js = ['js/pages/dashboard.js'];
    public $css = ['css/pages/dashboard.css'];
}
