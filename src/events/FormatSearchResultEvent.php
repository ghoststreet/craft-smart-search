<?php

namespace ghoststreet\craftsmartsearch\events;

use craft\elements\Entry;
use yii\base\Event;

/**
 * Fired once per formatted search result so listeners can project custom
 * field data into the API payload. Populate $fields; when non-empty it is
 * returned under the result's `fields` key.
 */
class FormatSearchResultEvent extends Event
{
    public Entry $element;
    public string $type;
    public array $fields = [];
}
