<?php

namespace ghoststreet\craftsmartsearch\events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Fired once per entry at index time. Populate $rules to attach weighted boost
 * rules: each rule is ['terms' => string[], 'weight' => int|float], and fires
 * when all its phrases appear (phrase-accurate, typo-tolerant) in the query.
 * The matched weight is added to the entry's search rank.
 */
class IndexBoostsEvent extends Event
{
    public ElementInterface $element;

    /** @var array<array{terms: string[], weight: int|float}> */
    public array $rules = [];
}
