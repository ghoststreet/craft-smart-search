<?php

namespace ghoststreet\craftsmartsearch\assets;

use craft\web\AssetBundle;

class IndexEntryAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [SmartSearchAsset::class];
        $this->js = ['js/pages/index-entry.js'];
        $this->css = ['css/pages/index-entry.css'];

        parent::init();
    }
}
