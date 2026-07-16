<?php

namespace ghoststreet\craftsmartsearch\assets;

class InsightsAsset extends PageAsset
{
    public $depends = [ChartAsset::class];
    public $js = ['js/pages/insights.js'];
}
