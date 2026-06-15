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
    'initialLimit' => 10,
    'showElementTypeLabel' => true,
];
```

### Settings

- **enableNestedElements** (boolean, default: `true`) - Whether to display the related elements that exist inside the CKEditor, Matrix or Neo fields of an element.
- **initialLimit** (integer, default: `10`) - Number of related elements to show initially before requiring "Show More" to expand the list.
- **showElementTypeLabel** (boolean, default: `true`) - Whether to display the element type labels (Entry, Category, Asset, Tag) next to each related element.

## Improvements

### Completed

- **Removed field-walking verification loop from `findIncomingRelationships()`** — The original code ran a `relatedTo` query to find candidate elements, then re-walked every field on every candidate calling `getFieldValue()` to confirm the relationship. This was the primary source of excess SQL queries. Craft's `relatedTo` query already reads the `craft_relations` table which is the authoritative source of truth written at save time, so the secondary verification pass was redundant.

- **Removed duplication and dead code from `findOutgoingRelationships()`** — Replaced the O(n) linear scan for duplicate detection with an O(1) ID-keyed hash set. Removed the per-element `getFieldLayout()` guard call, which was unnecessary for standard element types (Entry, Category, Asset, Tag) returned by Craft's field value API.

- **Moved element type pills outside anchor tags** — Repositioned the element type pill markup so it sits after the closing `<a>` tag (within the outer flex container) across all three element list sections — outgoing, nested, and incoming — improving accessibility and click-target semantics without changing the visual layout.

- **Section headings for grouped incoming/outgoing relationships** — Added section-name headings (Craft section, category group, or asset volume name) within the References and Referenced by panels. Elements are pre-sorted in PHP by section name so headings render correctly without duplication. Grouping is computed server-side via a `groupElementsBySection()` helper rather than relying on Twig variable tracking, which avoids scoping issues. Each outgoing section heading carries an info tooltip listing the field handles on the current element that contribute relations to that section, styled as monospace code chips to match Craft's own handle display. Section heading `min-height` is overridden in CSS to remove the default `.field` spacing. The show/hide JS automatically collapses section headings when all items in their group fall beyond the initial display limit.

### Planned

- **Performance optimisations** — Further profiling and query reduction across the render path.

- **Hard limit on incoming and outgoing relationships** — Cap the total number of relations fetched from the database to prevent excessive load on entries with very large relation sets.

- **Limit relations by section and entry type** — Add configuration to scope incoming/outgoing scans to specific sections or entry types, reducing unnecessary queries for entries that are only relevant to a subset of the content model.
