<?php

namespace ghoststreet\craftsmartsearch\assets;

use craft\web\AssetBundle;

/**
 * Base for the per-page CP bundles. Each subclass declares only its own $js,
 * $css, and any extra $depends beyond the shared base bundle.
 */
abstract class PageAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = array_merge([SmartSearchAsset::class], $this->depends);

        parent::init();
    }
}
