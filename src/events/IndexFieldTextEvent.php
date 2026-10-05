<?php

namespace ghoststreet\craftsmartsearch\events;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use yii\base\Event;

/**
 * Fired once per field during indexing so listeners can rewrite the text a
 * field contributes to the embedded content. Mutate $text; it becomes what
 * gets indexed for that field (e.g. turn a bare `4` into "4 bathrooms").
 */
class IndexFieldTextEvent extends Event
{
    public ElementInterface $element;
    public FieldInterface $field;
    public mixed $value;
    public string $text;
}
