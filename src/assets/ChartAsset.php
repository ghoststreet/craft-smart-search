<?php

namespace ghoststreet\craftsmartsearch\assets;

/**
 * Shared chart vendor + theme + wrapper. Page bundles depend on this whenever
 * they need to render a chart.
 */
class ChartAsset extends PageAsset
{
    public $js = [
        'vendor/chart.umd.min.js',
        'js/core/chart-theme.js',
        'js/components/chart.js',
    ];
}
