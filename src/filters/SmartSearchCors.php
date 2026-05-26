<?php

namespace ghoststreet\craftsmartsearch\filters;

use craft\filters\Cors as CraftCors;
use ghoststreet\craftsmartsearch\SmartSearch;

/**
 * CORS filter for the public Smart Search API.
 *
 * Yii's Cors filter matches origins against a static array set at construction
 * time. Smart Search lets admins edit the allowed-origins list at runtime, so
 * we override prepareHeaders() to populate the Origin list from current
 * settings on every request. Same-origin requests don't trigger CORS, so the
 * site's own host doesn't need to be auto-added to the allowlist.
 */
class SmartSearchCors extends CraftCors
{
    public function prepareHeaders($requestHeaders)
    {
        $this->cors['Origin'] = SmartSearch::getInstance()->getSettings()->getAllowedOriginsList();

        return parent::prepareHeaders($requestHeaders);
    }
}
