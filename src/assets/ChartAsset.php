<?php

namespace ghoststreet\craftsmartsearch\assets;

use craft\web\AssetBundle;

/**
 * Shared chart vendor + theme + wrapper. Page bundles depend on this whenever
 * they need to render a chart.
 */
class ChartAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [SmartSearchAsset::class];
        $this->js = [
            'vendor/chart.umd.min.js',
            'js/core/chart-theme.js',
            'js/components/chart.js',
        ];
        parent::init();
    }
}
