<?php

namespace ghoststreet\craftsmartsearch\assets;

use craft\web\AssetBundle;

class SettingsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [SmartSearchAsset::class];
        $this->js = ['js/pages/settings.js'];
        $this->css = ['css/pages/settings.css'];

        parent::init();
    }
}
