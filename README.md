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

## Improvements

### Completed

- **Removed field-walking verification loop from `findIncomingRelationships()`** — The original code ran a `relatedTo` query to find candidate elements, then re-walked every field on every candidate calling `getFieldValue()` to confirm the relationship. This was the primary source of excess SQL queries. Craft's `relatedTo` query already reads the `craft_relations` table which is the authoritative source of truth written at save time, so the secondary verification pass was redundant.

- **Removed duplication and dead code from `findOutgoingRelationships()`** — Replaced the O(n) linear scan for duplicate detection with an O(1) ID-keyed hash set. Removed the per-element `getFieldLayout()` guard call, which was unnecessary for standard element types (Entry, Category, Asset, Tag) returned by Craft's field value API.

- **Moved element type pills outside anchor tags** — Repositioned the element type pill markup so it sits after the closing `<a>` tag (within the outer flex container) across all three element list sections — outgoing, nested, and incoming — improving accessibility and click-target semantics without changing the visual layout.

- **Section headings for grouped incoming/outgoing relationships** — Added section-name headings (Craft section, category group, or asset volume name) within the References and Referenced by panels. Elements are pre-sorted in PHP by section name so headings render correctly without duplication. Grouping is computed server-side via a `groupElementsBySection()` helper rather than relying on Twig variable tracking, which avoids scoping issues. Each outgoing section heading carries an info tooltip listing the field handles on the current element that contribute relations to that section, styled as monospace code chips to match Craft's own handle display. Section heading `min-height` is overridden in CSS to remove the default `.field` spacing. The show/hide JS automatically collapses section headings when all items in their group fall beyond the initial display limit.

- **Hard limit on incoming and outgoing relationships** — Added `incomingLimit` and `outgoingLimit` settings (integer or null). For incoming, the limit is applied at the database query level per element type and enforced as a global total cap across all types. For outgoing, the field-walking loop exits early once the cap is reached. Both are configurable in the CP settings page and via `config/related-elements.php`. Default is `null` (no limit).

- **Performance optimisations**
  - **Render-level HTML caching** — Wrapped the entire sidebar computation in Craft's data cache, keyed on `elementId` and `siteId`, with a `TagDependency` on the `related-elements` tag. Cache is invalidated automatically whenever any element is saved or deleted by listening to `Element::EVENT_AFTER_SAVE` and `Element::EVENT_AFTER_DELETE`. On repeat views of the same entry all database queries are bypassed entirely.
  - **O(1) deduplication in `findNestedElements()`** — The nested element traversal (Matrix, Neo, CKEditor) was using a nested `foreach` to check for duplicate element IDs — an O(n) scan per element added, growing quadratic with block count. Replaced with the same O(1) ID-keyed storage approach already used in `findOutgoingRelationships()`, storing elements keyed by their ID in the `$nestedRelatedElements` map rather than appending to a sequential array.

- **Restrict sidebar by section** — Added `allowedSections` setting (array of section handles). When non-empty, the sidebar exits immediately for entries not in the specified sections — before any cache lookup or database queries. Non-entry elements (categories, assets, tags) are always shown. Configurable in the CP settings page and via `config/related-elements.php`.

### Planned

- **Performance optimisations** — Further query and CPU reduction opportunities remaining in the render path:
  - **Schwartzian transform for sort and group pre-computation** — The `usort` comparators in `findOutgoingRelationships()` and `findIncomingRelationships()` access `$el->section->name ?? $el->group->name ?? $el->volume->name` on every comparison (O(log n) per element). `groupElementsBySection()` then reads the same chain again for every element. Pre-computing a `[$sectionName, $title, $element]` tuple array once and sorting on indices 0–1 would reduce each property chain access to exactly once.
  - **Single `craft_relations` query for outgoing relationships** — `findOutgoingRelationships()` currently walks every custom field on the element layout, filters for relation field instances, and calls `getFieldValue()` for each. A single raw query on `craft_relations WHERE sourceId = :id` would return all outgoing relations in one shot — the same shortcut already applied to the incoming path — with `getFieldById()` (cached internally by Craft) to resolve field handles per distinct `fieldId`.
  - **Matrix block batching in `findNestedElements()`** — When nested elements are enabled, the traversal fires `n_blocks × n_types` queries — one `relatedTo($block)` per block per element type. Collecting all blocks for a field first and running `relatedTo(['or', ...$blocks])` once per type would reduce this to `n_types` queries per field regardless of block count.
  - **`getSiteById()` redundant call** — `renderTemplate()` calls `getSiteById()` to resolve the current site handle, and `findNestedElements()` calls it again independently. The handle could be passed as a parameter to avoid the duplicate lookup.
