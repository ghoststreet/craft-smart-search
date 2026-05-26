<?php

namespace ghoststreet\craftsmartsearch\filters;

use Craft;
use craft\filters\Cors as CraftCors;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * CORS filter for the public Smart Search API.
 *
 * Yii's Cors filter matches origins against a static array set at construction
 * time. Smart Search lets admins edit the allowed-origins list at runtime, so
 * we override prepareHeaders() to populate the Origin list from current
 * settings on every request.
 */
class SmartSearchCors extends CraftCors
{
    public function prepareHeaders($requestHeaders)
    {
        $allowed = SmartSearch::getInstance()->getSettings()->getAllowedOriginsList();
        $siteHost = Craft::$app->getRequest()->getHostInfo();

        if ($siteHost !== null && $siteHost !== '' && !in_array($siteHost, $allowed, true)) {
            $allowed[] = $siteHost;
        }

        $this->cors['Origin'] = $allowed;

        return parent::prepareHeaders($requestHeaders);
    }
}
