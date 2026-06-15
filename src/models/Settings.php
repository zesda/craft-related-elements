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
    public array $allowedSections = [];

    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['allowedSections'])) {
            if (is_string($values['allowedSections'])) {
                $values['allowedSections'] = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $values['allowedSections']))));
            } elseif (is_array($values['allowedSections'])) {
                $values['allowedSections'] = array_values(array_filter(array_map('trim', $values['allowedSections'])));
            }
        }
        parent::setAttributes($values, $safeOnly);
    }

    public function rules(): array
    {
        return [
            [['enableNestedElements', 'enableTemplateCache', 'showElementTypeLabel'], 'boolean'],
            [['initialLimit'], 'integer', 'min' => 1, 'max' => 100],
            [['initialLimit'], 'default', 'value' => 10],
            [['outgoingLimit', 'incomingLimit'], 'default', 'value' => null],
            [['outgoingLimit', 'incomingLimit'], 'integer', 'min' => 1, 'skipOnEmpty' => true],
            [['allowedSections'], 'default', 'value' => []],
            [['allowedSections'], 'each', 'rule' => ['string']],
        ];
    }
}
