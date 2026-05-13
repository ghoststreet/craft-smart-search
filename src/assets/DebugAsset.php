<?php

namespace ghoststreet\craftaisearch\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class DebugAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/cp';
        $this->depends = [CpAsset::class];
        $this->css = ['debug.css'];

        parent::init();
    }
}
