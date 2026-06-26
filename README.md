# Related Elements

Displays related elements of an entry, category or asset in the control panel edit view sidebar.

<img src="screenshot.png" alt="Screenshot" width="500">

## Requirements

This plugin requires Craft CMS 5.0.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "Related Elements". Then press "Install".

### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require mindseeker-media/craft-related-elements

# tell Craft to install the plugin
./craft plugin/install related-elements
```

## Configuration

Configure options in the control panel under Settings → Related Elements or create a configuration file config/related-elements.php.

```php
<?php

return [
    'enableNestedElements' => true,
    'enableTemplateCache' => false,
    'initialLimit' => 10,
    'showElementTypeLabel' => true,
    'outgoingLimit' => null, // null for no limit
    'incomingLimit' => null, // null for no limit
    'allowedSections' => [], // empty array for all sections
];
```

### Settings

- **enableNestedElements** (boolean, default: `true`) - Whether to display the related elements that exist inside the CKEditor, Matrix or Neo fields of an element.
- **enableTemplateCache** (boolean, default: `false`) - Cache the sidebar HTML per element and site, invalidated automatically on element save or delete. Disabled by default; enable when repeat CP views of the same entry are common and Craft's data cache is properly configured.
- **initialLimit** (integer, default: `10`) - Number of related elements to show initially before requiring "Show More" to expand the list.
- **showElementTypeLabel** (boolean, default: `true`) - Whether to display the element type labels (Entry, Category, Asset, Tag) next to each related element.
- **outgoingLimit** (integer|null, default: `null`) - Maximum number of outgoing relationships (elements this entry references) to load. `null` for no limit.
- **incomingLimit** (integer|null, default: `null`) - Maximum number of incoming relationships (elements that reference this entry) to load. The limit is applied at the database query level per element type and as a global total cap. `null` for no limit.
- **allowedSections** (array, default: `[]`) - Restrict the sidebar to entries belonging to the specified section handles. An empty array shows the sidebar for all sections. Non-entry elements (categories, assets, tags) are always shown regardless of this setting.

## Implementation notes

### Database queries

Incoming relationships use a single `relatedTo(targetElement:)` query per element type — Craft's `relatedTo` reads `craft_relations` directly, which is the authoritative source written at save time, so no secondary verification pass is needed.

Outgoing relationships issue a single `SELECT fieldId, targetId FROM craft_relations WHERE sourceId = :id`, build a `targetId → [fieldIds]` map, then load elements per-type via `->id($allTargetIds)` queries. Field handles for the tooltip chips are resolved via `getFieldById()`, which Craft caches internally.

Nested element traversal (Matrix/Neo) collects all valid blocks for a field first, then runs a single `relatedTo(['or', ...$blocks])` query per element type — reducing `n_blocks × n_types` queries to `n_types` queries per field regardless of block count. Per-block iteration only runs for CKEditor field processing and recursive descent into nested Matrix/Neo fields.

### Sorting and grouping

Both `findOutgoingRelationships()` and `findIncomingRelationships()` use a Schwartzian transform when sorting: `section/group/volume->name` is resolved once per element into a `[$sectionName, $title, $element]` tuple, the tuples are sorted, then the elements are extracted. `groupElementsBySection()` uses the same `sectionLabel()` helper. This means the property chain is accessed once per element across both sort and group, rather than O(n log n) times in the comparator.

### Caching

The entire sidebar computation can be wrapped in Craft's data cache (opt-in via `enableTemplateCache`), keyed on `elementId` and `siteId`, with a `TagDependency` on the `related-elements` tag. The cache is invalidated automatically on `Element::EVENT_AFTER_SAVE` and `Element::EVENT_AFTER_DELETE`.

### Site handle resolution

`buildSidebarHtml()` resolves `$currentSiteHandle` once via `getSiteById()` and passes it through to `findNestedElements()` and both relationship finders, avoiding redundant lookups in nested calls.

### Deduplication

All three relationship finders use O(1) ID-keyed maps for deduplication rather than linear scans.
