<?php

namespace mindseekermedia\craftrelatedelements\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $enableNestedElements = true;
    public bool $enableTemplateCache = false;
    public int $initialLimit = 10;
    public bool $showElementTypeLabel = true;
    public ?int $outgoingLimit = null;
    public ?int $incomingLimit = null;

    public function rules(): array
    {
        return [
            [['enableNestedElements', 'enableTemplateCache', 'showElementTypeLabel'], 'boolean'],
            [['initialLimit'], 'integer', 'min' => 1, 'max' => 100],
            [['initialLimit'], 'default', 'value' => 10],
            [['outgoingLimit', 'incomingLimit'], 'default', 'value' => null],
            [['outgoingLimit', 'incomingLimit'], 'integer', 'min' => 1, 'skipOnEmpty' => true],
        ];
    }
}
