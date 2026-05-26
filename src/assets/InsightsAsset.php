<?php

namespace ghoststreet\craftsmartsearch\assets;

use craft\web\AssetBundle;

class InsightsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [SmartSearchAsset::class, ChartAsset::class];
        $this->js = [
            'js/pages/insights.js',
        ];

        parent::init();
    }
}
