<?php

namespace mindseekermedia\craftrelatedelements;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\events\DefineHtmlEvent;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use mindseekermedia\craftrelatedelements\models\Settings;
use yii\base\Event;

/**
 * Related Elements plugin
 *
 * @method static RelatedElements getInstance()
 * @author Mindseeker Media <dev@mindseeker.media>
 * @copyright Mindseeker Media
 * @license MIT
 */
class RelatedElements extends Plugin
{
    private static ?RelatedElements $plugin;
    /**
     * @var null|Settings
     */
    public static ?Settings $settings = null;
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;
        /** @var Settings $settings */
        $settings = self::$plugin->getSettings();
        self::$settings = $settings;

        Craft::$app->onInit(fn() => $this->attachEventHandlers());
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            fn(DefineHtmlEvent $event) => $event->html .=
                ($event->sender instanceof Entry ||
                    $event->sender instanceof Category ||
                    $event->sender instanceof Asset ||
                    $event->sender instanceof Tag)
                    ? $this->renderTemplate($event->sender)
                    : ''
        );
    }

    private function renderTemplate(Element $element): string
    {
        $relatedTypes = [
            'Entry' => Entry::class,
            'Category' => Category::class,
            'Asset' => Asset::class,
            'Tag' => Tag::class,
        ];

        $outgoingRelatedElements = [
            'Entry' => [],
            'Category' => [],
            'Asset' => [],
            'Tag' => [],
        ];
        $incomingRelatedElements = [
            'Entry' => [],
            'Category' => [],
            'Asset' => [],
            'Tag' => [],
        ];
        $nestedRelatedElements = [];
        $hasResults = false;
        $enableNestedElements = self::$settings->enableNestedElements;
        $currentSiteId = $element->siteId;
        $currentSiteHandle = Craft::$app->getSites()->getSiteById($currentSiteId)->handle;

        // Find outgoing relationships (elements this entry references)
        $outgoingFieldsBySectionName = [];
        $this->findOutgoingRelationships($element, $relatedTypes, $outgoingRelatedElements, $hasResults, $currentSiteHandle, $outgoingFieldsBySectionName);

        // Find incoming relationships (elements that reference this entry)
        $this->findIncomingRelationships($element, $relatedTypes, $incomingRelatedElements, $hasResults, $currentSiteHandle);

        if ($enableNestedElements) {
            $fieldLayout = $element->getFieldLayout();
            $this->findNestedElements(
                $fieldLayout ? $fieldLayout->getCustomFields() : [],
                $element,
                $nestedRelatedElements,
                $hasResults,
                $relatedTypes
            );
        }

        // Determine element type for display text
        $elementType = 'element';
        if ($element instanceof Entry) {
            $elementType = 'entry';
        } elseif ($element instanceof Category) {
            $elementType = 'category';
        } elseif ($element instanceof Asset) {
            $elementType = 'asset';
        } elseif ($element instanceof Tag) {
            $elementType = 'tag';
        }

        $outgoingGroups = $this->groupElementsBySection($outgoingRelatedElements);
        $incomingGroups = $this->groupElementsBySection($incomingRelatedElements);

        return Craft::$app->getView()->renderTemplate(
            'related-elements/_element-sidebar',
            [
                'hasResults' => $hasResults,
                'outgoingGroups' => $outgoingGroups,
                'outgoingFieldsBySectionName' => $outgoingFieldsBySectionName,
                'incomingGroups' => $incomingGroups,
                'nestedRelatedElements' => $nestedRelatedElements,
                'initialLimit' => self::$settings->initialLimit,
                'elementType' => $elementType,
                'showElementTypeLabel' => self::$settings->showElementTypeLabel,
            ]
        );
    }

    private function findOutgoingRelationships(Element $element, array $relatedTypes, array &$outgoingRelatedElements, bool &$hasResults, string $currentSiteHandle, array &$outgoingFieldsBySectionName = []): void
    {
        try {
            $fieldLayout = $element->getFieldLayout();
            if (!$fieldLayout) {
                return;
            }

            $fields = $fieldLayout->getCustomFields();
            $seenIds = array_fill_keys(array_keys($relatedTypes), []);
            $fallbackLabels = ['Entry' => 'Entries', 'Category' => 'Categories', 'Asset' => 'Assets', 'Tag' => 'Tags'];

            foreach ($fields as $field) {
                if (!$field || !$field->handle || !($field instanceof BaseRelationField)) {
                    continue;
                }

                try {
                    $fieldValue = $element->getFieldValue($field->handle);
                    if (!$fieldValue) {
                        continue;
                    }

                    $relatedElements = [];
                    if (is_iterable($fieldValue)) {
                        foreach ($fieldValue as $relatedElement) {
                            if ($relatedElement instanceof Element) {
                                $relatedElements[] = $relatedElement;
                            }
                        }
                    } elseif ($fieldValue instanceof Element) {
                        $relatedElements[] = $fieldValue;
                    }

                    foreach ($relatedElements as $relatedElement) {
                        foreach ($relatedTypes as $type => $class) {
                            if ($relatedElement instanceof $class) {
                                if (!isset($seenIds[$type][$relatedElement->id])) {
                                    $seenIds[$type][$relatedElement->id] = true;
                                    $outgoingRelatedElements[$type][] = $relatedElement;
                                    $hasResults = true;

                                    $sectionName = $relatedElement->section->name
                                        ?? $relatedElement->group->name
                                        ?? $relatedElement->volume->name
                                        ?? ($fallbackLabels[$type] ?? $type);
                                    if (!in_array($field->handle, $outgoingFieldsBySectionName[$sectionName] ?? [], true)) {
                                        $outgoingFieldsBySectionName[$sectionName][] = $field->handle;
                                    }
                                }
                                break;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Craft::warning("Error processing field {$field->handle} for outgoing relationships: " . $e->getMessage(), __METHOD__);
                }
            }

            foreach (array_keys($relatedTypes) as $type) {
                if (!empty($outgoingRelatedElements[$type])) {
                    usort($outgoingRelatedElements[$type], fn($a, $b) =>
                        strcmp(
                            ($a->section->name ?? $a->group->name ?? $a->volume->name ?? ''),
                            ($b->section->name ?? $b->group->name ?? $b->volume->name ?? '')
                        ) ?: strcmp($a->title ?? '', $b->title ?? '')
                    );
                }
            }
        } catch (\Throwable $e) {
            Craft::error("Error finding outgoing relationships: " . $e->getMessage(), __METHOD__);
        }
    }

    private function findIncomingRelationships(Element $element, array $relatedTypes, array &$incomingRelatedElements, bool &$hasResults, string $currentSiteHandle): void
    {
        try {
            // relatedTo with targetElement queries craft_relations directly — results are already verified
            foreach ($relatedTypes as $type => $class) {
                $elements = $class::find()
                    ->relatedTo([
                        'targetElement' => $element,
                        'field' => null,
                    ])
                    ->status(null)
                    ->site('*')
                    ->unique()
                    ->preferSites([$currentSiteHandle])
                    ->orderBy('title')
                    ->all();

                foreach ($elements as $el) {
                    if ($el->id === $element->id) {
                        continue;
                    }
                    $incomingRelatedElements[$type][] = $el;
                    $hasResults = true;
                }

                if (!empty($incomingRelatedElements[$type])) {
                    usort($incomingRelatedElements[$type], fn($a, $b) =>
                        strcmp(
                            ($a->section->name ?? $a->group->name ?? $a->volume->name ?? ''),
                            ($b->section->name ?? $b->group->name ?? $b->volume->name ?? '')
                        ) ?: strcmp($a->title ?? '', $b->title ?? '')
                    );
                }
            }
        } catch (\Throwable $e) {
            Craft::error("Error finding incoming relationships: " . $e->getMessage(), __METHOD__);
        }
    }

    private function groupElementsBySection(array $typeElementMap): array
    {
        $groups = [];
        foreach ($typeElementMap as $type => $elements) {
            $currentSection = null;
            foreach ($elements as $element) {
                $sectionName = $element->section->name
                    ?? $element->group->name
                    ?? $element->volume->name
                    ?? $type;
                if ($sectionName !== $currentSection) {
                    $currentSection = $sectionName;
                    $groups[] = ['section' => $sectionName, 'type' => $type, 'elements' => []];
                }
                $groups[count($groups) - 1]['elements'][] = $element;
            }
        }
        return $groups;
    }

    private function findNestedElements(array $fields, Element $element, array &$nestedRelatedElements, bool &$hasResults, array $relatedTypes, string $fieldPath = ''): void
    {
        if (!$element || !$element->siteId) {
            return;
        }

        try {
            $currentSiteId = $element->siteId;
            $currentSite = Craft::$app->getSites()->getSiteById($currentSiteId);

            if (!$currentSite) {
                return;
            }

            $currentSiteHandle = $currentSite->handle;

            foreach ($fields as $field) {
                if (!$field || !$field->handle) {
                    continue;
                }

                $isMatrixField = $field instanceof Matrix;
                $isNeoField = class_exists('\benf\neo\Field') && get_class($field) === \benf\neo\Field::class;
                $isCKEditorField = class_exists('\craft\ckeditor\Field') && $field instanceof \craft\ckeditor\Field;

                if ($isMatrixField || $isNeoField) {
                    try {
                        $blocks = $element->getFieldValue($field->handle);

                        if (!$blocks) {
                            continue;
                        }

                        $fieldName = $fieldPath ? $fieldPath . ' → ' . $field->name : $field->name;

                        if (!isset($nestedRelatedElements[$fieldName])) {
                            $nestedRelatedElements[$fieldName] = [];
                        }

                        foreach ($blocks->all() as $block) {
                            if (!$block) {
                                continue;
                            }

                            try {
                                $fieldLayout = $block->getFieldLayout();
                                if (!$fieldLayout) {
                                    continue;
                                }

                                // For Neo blocks, ensure they have a valid type
                                if ($isNeoField && $block instanceof \benf\neo\elements\Block) {
                                    if (!$block->getType()) {
                                        continue;
                                    }
                                }

                                foreach ($relatedTypes as $type => $class) {
                                    $newElements = $class::find()
                                        ->relatedTo($block)
                                        ->status(null)
                                        ->site('*')
                                        ->unique()
                                        ->preferSites([$currentSiteHandle])
                                        ->orderBy('title')
                                        ->all();

                                    $filteredElements = array_filter($newElements, function($el) {
                                        try {
                                            return $el->getFieldLayout() !== null;
                                        } catch (\Throwable $e) {
                                            Craft::error("Error checking nested element layout {$el->id}: " . $e->getMessage(), __METHOD__);
                                            return false;
                                        }
                                    });

                                    if (!empty($filteredElements)) {
                                        if (!isset($nestedRelatedElements[$fieldName][$type])) {
                                            $nestedRelatedElements[$fieldName][$type] = [];
                                        }

                                        foreach ($filteredElements as $newElement) {
                                            $exists = false;
                                            foreach ($nestedRelatedElements[$fieldName][$type] as $existingElement) {
                                                if ($existingElement->id === $newElement->id) {
                                                    $exists = true;
                                                    break;
                                                }
                                            }

                                            if (!$exists) {
                                                $nestedRelatedElements[$fieldName][$type][] = $newElement;
                                                $hasResults = true;
                                            }
                                        }
                                    }
                                }

                                // Recursively check for nested Matrix/Neo fields within this block
                                $blockFields = $fieldLayout->getCustomFields();
                                if (!empty($blockFields)) {
                                    // Also check for CKEditor fields within this block that might contain embedded entries
                                    foreach ($blockFields as $blockField) {
                                        if (!$blockField || !$blockField->handle) {
                                            continue;
                                        }

                                        $isCKEditorField = class_exists('\craft\ckeditor\Field') && $blockField instanceof \craft\ckeditor\Field;

                                        if ($isCKEditorField) {
                                            try {
                                                $ckeditorFieldValue = $block->getFieldValue($blockField->handle);

                                                if ($ckeditorFieldValue && is_iterable($ckeditorFieldValue)) {
                                                    foreach ($ckeditorFieldValue as $chunk) {
                                                        // Check if this chunk is an entry type (embedded entry)
                                                        if (isset($chunk->type) && $chunk->type === 'entry' && isset($chunk->entry)) {
                                                            $embeddedEntry = $chunk->entry;
                                                            if ($embeddedEntry instanceof Element) {
                                                                // Categorize the embedded entry by type
                                                                foreach ($relatedTypes as $type => $class) {
                                                                    if ($embeddedEntry instanceof $class) {
                                                                        try {
                                                                            if ($embeddedEntry->getFieldLayout() !== null) {
                                                                                if (!isset($nestedRelatedElements[$fieldName][$type])) {
                                                                                    $nestedRelatedElements[$fieldName][$type] = [];
                                                                                }

                                                                                // Check if element already exists to avoid duplicates
                                                                                $exists = false;
                                                                                foreach ($nestedRelatedElements[$fieldName][$type] as $existingElement) {
                                                                                    if ($existingElement->id === $embeddedEntry->id) {
                                                                                        $exists = true;
                                                                                        break;
                                                                                    }
                                                                                }

                                                                                if (!$exists) {
                                                                                    $nestedRelatedElements[$fieldName][$type][] = $embeddedEntry;
                                                                                    $hasResults = true;
                                                                                }
                                                                            }
                                                                        } catch (\Throwable $e) {
                                                                            Craft::error("Error checking field layout for CKEditor embedded element {$embeddedEntry->id}: " . $e->getMessage(), __METHOD__);
                                                                        }
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                            } catch (\Throwable $e) {
                                                Craft::warning("Error processing CKEditor field {$blockField->handle} in nested block: " . $e->getMessage(), __METHOD__);
                                                continue;
                                            }
                                        }
                                    }

                                    $this->findNestedElements(
                                        $blockFields,
                                        $block,
                                        $nestedRelatedElements,
                                        $hasResults,
                                        $relatedTypes,
                                        $fieldName
                                    );
                                }
                            } catch (\Throwable $e) {
                                // Log the error but continue processing other blocks
                                Craft::warning('Error processing block in Related Elements plugin: ' . $e->getMessage(), __METHOD__);
                                continue;
                            }
                        }
                    } catch (\Throwable $e) {
                        // Log the error but continue processing other fields
                        Craft::warning('Error processing field in Related Elements plugin: ' . $e->getMessage(), __METHOD__);
                        continue;
                    }
                } elseif ($isCKEditorField) {
                    // Handle CKEditor fields at the top level
                    try {
                        $ckeditorFieldValue = $element->getFieldValue($field->handle);

                        if (!$ckeditorFieldValue) {
                            continue;
                        }

                        $fieldName = $fieldPath ? $fieldPath . ' → ' . $field->name : $field->name;

                        if (!isset($nestedRelatedElements[$fieldName])) {
                            $nestedRelatedElements[$fieldName] = [];
                        }

                        if (is_iterable($ckeditorFieldValue)) {
                            foreach ($ckeditorFieldValue as $chunk) {
                                // Check if this chunk is an entry type (embedded entry)
                                if (isset($chunk->type) && $chunk->type === 'entry' && isset($chunk->entry)) {
                                    $embeddedEntry = $chunk->entry;
                                    if ($embeddedEntry instanceof Element) {
                                        // Categorize the embedded entry by type
                                        foreach ($relatedTypes as $type => $class) {
                                            if ($embeddedEntry instanceof $class) {
                                                try {
                                                    if ($embeddedEntry->getFieldLayout() !== null) {
                                                        if (!isset($nestedRelatedElements[$fieldName][$type])) {
                                                            $nestedRelatedElements[$fieldName][$type] = [];
                                                        }

                                                        // Check if element already exists to avoid duplicates
                                                        $exists = false;
                                                        foreach ($nestedRelatedElements[$fieldName][$type] as $existingElement) {
                                                            if ($existingElement->id === $embeddedEntry->id) {
                                                                $exists = true;
                                                                break;
                                                            }
                                                        }

                                                        if (!$exists) {
                                                            $nestedRelatedElements[$fieldName][$type][] = $embeddedEntry;
                                                            $hasResults = true;
                                                        }
                                                    }
                                                } catch (\Throwable $e) {
                                                    Craft::error("Error checking field layout for CKEditor embedded element {$embeddedEntry->id}: " . $e->getMessage(), __METHOD__);
                                                }
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        // Log the error but continue processing other fields
                        Craft::warning("Error processing CKEditor field {$field->handle}: " . $e->getMessage(), __METHOD__);
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Log the error but don't throw it
            Craft::error('Error in Related Elements plugin: ' . $e->getMessage(), __METHOD__);
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return \Craft::$app->getView()->renderTemplate(
            'related-elements/settings',
            [ 'settings' => $this->getSettings() ]
        );
    }
}
