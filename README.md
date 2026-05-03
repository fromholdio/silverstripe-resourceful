# SilverStripe Resourceful

A powerful configuration-driven module for SilverStripe that eliminates repetitive inheritance pattern setup. Resourceful provides a consistent UX and ORM structure for fields and relations that can inherit from parent objects or fall back to site-wide defaults.

## Table of Contents

- [Overview](#overview)
- [Why This Module?](#why-this-module)
- [Requirements](#requirements)
- [Installation](#installation)
- [Core Concepts](#core-concepts)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Usage](#usage)
- [Advanced Features](#advanced-features)
- [API Reference](#api-reference)
- [Real-World Examples](#real-world-examples)
- [License](#license)

## Overview

Resourceful solves a common problem in hierarchical SilverStripe projects: providing users with the ability to inherit values or relations from parent pages or use site-wide defaults, without writing repetitive boilerplate code for each field.

**What it provides:**
- **Configuration-driven inheritance** - Define inheritance patterns in YAML
- **Automatic CMS fields** - Generates "Inherit from parent" checkboxes and source selectors
- **Flexible source options** - Local, parent, site, or custom sources
- **Consistent UX** - Same interface pattern across all inheritable fields
- **Zero boilerplate** - No repetitive code for each inheritable field

## Why This Module?

Without Resourceful, implementing field inheritance requires:

1. **Database fields** for each inheritable value:
   ```php
   'SidebarArea_DoInherit' => 'Boolean',
   'SidebarArea_Source' => 'Varchar(10)',
   'SidebarArea_Local' => 'has_one relation',
   ```

2. **Getter methods** with inheritance logic:
   ```php
   public function getSidebarArea() {
       if ($this->SidebarArea_DoInherit && $this->Parent()->exists()) {
           return $this->Parent()->getSidebarArea();
       }
       switch ($this->SidebarArea_Source) {
           case 'site': return SiteConfig::curr()->SidebarArea();
           case 'local': return $this->SidebarArea_Local();
           case 'none': return null;
       }
   }
   ```

3. **CMS fields** with display logic:
   ```php
   $fields->addFieldToTab('Root.Sidebar',
       CheckboxField::create('SidebarArea_DoInherit', 'Inherit from parent')
   );
   $wrapper = Wrapper::create(
       OptionsetField::create('SidebarArea_Source', 'Source', [...])
   );
   $wrapper->displayIf('SidebarArea_DoInherit')->isNotChecked();
   // ... more field logic
   ```

**With Resourceful**, all of this becomes:

```yaml
Page:
  resourceful:
    SidebarArea:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
        default: 'site'
      relations:
        site: '->getSidebarConfig'
      cms_fields:
        tab_path: 'Root.Sidebar'
```

```php
public function getSidebarArea() {
    return $this->getResourcefulValue('SidebarArea');
}
```

That's it. Resourceful handles everything else.

## Requirements

- SilverStripe CMS ^6.0
- PHP 8.3+
- fromholdio/silverstripe-checkboxfieldgroup ^1.2.0
- fromholdio/silverstripe-cms-fields-placement ^1.2.0
- unclecheese/display-logic ^4.0.0

## Installation

```bash
composer require fromholdio/silverstripe-resourceful
```

## Core Concepts

### Sources

A **source** is where a value comes from. Resourceful provides built-in sources:

- **`local`** - Value stored directly on this object
- **`parent`** - Inherited from parent object (hierarchical)
- **`site`** - Site-wide default (from SiteConfig or custom)
- **`none`** - No value (null)
- **`default`** - Use the configured default source

### Inheritance

When **inherit** is enabled, the value is retrieved from the parent object recursively until a non-inheriting value is found.

### Source Selection

Users can **select** which source to use via CMS fields (unless forced by configuration).

### Configuration Structure

Each resourceful field has:
- **Name** - The field name (e.g., 'SidebarArea')
- **Sources** - Which sources are available and which is default
- **Values** - How to retrieve values from each source (field names or methods)
- **Relations** - How to traverse to parent/site objects
- **CMS Fields** - Where to place fields in the CMS

## Quick Start

### 1. Apply Extension

```php
use Fromholdio\Resourceful\Extensions\ResourcefulExtension;
use SilverStripe\CMS\Model\SiteTree;

class Page extends SiteTree
{
    private static $extensions = [
        ResourcefulExtension::class,
    ];
}
```

### 2. Configure Resourceful Field

```yaml
Page:
  resourceful:
    SidebarContent:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
        default: 'site'
      relations:
        site: 'Site'
      cms_fields:
        tab_path: 'Root.Sidebar'
```

### 3. Add Database Fields

```php
class Page extends SiteTree
{
    private static $db = [
        'SidebarContent_DoInherit' => 'Boolean',
        'SidebarContent_Source' => 'Varchar(10)',
        'SidebarContent_Local' => 'HTMLText',
    ];

    private static $defaults = [
        'SidebarContent_DoInherit' => true,
        'SidebarContent_Source' => 'default',
    ];
}
```

### 4. Create Getter Method

```php
public function getSidebarContent(): ?string
{
    return $this->getResourcefulValue('SidebarContent');
}
```

### 5. Use in Templates

```html
<% if $SidebarContent %>
    <aside>
        $SidebarContent
    </aside>
<% end_if %>
```

That's it! Resourceful automatically:
- Generates CMS fields with inheritance checkbox
- Handles source selection UI
- Traverses parent hierarchy
- Falls back to site default
- Manages display logic

## Configuration

### Basic Configuration

```yaml
Page:
  resourceful:
    FieldName:
      enabled: true  # Enable/disable this resourceful field
      sources:
        force: null  # Force a specific source (overrides user selection)
        inherit: 'parent'  # Enable inheritance from parent
        select: 'site|local|none'  # Available sources for user selection
        default: 'site'  # Default source when none selected
      values:
        local: 'FieldName_Local'  # Field name for local value
        parent: 'FieldName'  # Field name on parent
        site: 'FieldName'  # Field name on site config
      relations:
        parent: 'Parent'  # Relation to parent object
        site: 'Site'  # Relation to site object
      cms_fields:
        tab_path: 'Root.Main'  # Where to place CMS fields
      source_field_class: 'SilverStripe\Forms\OptionsetField'  # Field class for source selector
```

### Source Configuration

**`sources.force`** - Force a specific source, ignoring user selection:
```yaml
sources:
  force: 'site'  # Always use site default
```

**`sources.inherit`** - Enable inheritance from parent:
```yaml
sources:
  inherit: 'parent'  # Inherit from parent when checkbox checked
```

**`sources.select`** - Available sources for user selection:
```yaml
sources:
  select: 'site|local|none'  # User can choose between these
```

**`sources.default`** - Default source when none selected:
```yaml
sources:
  default: 'site'  # Default to site-wide value
```

### Value Configuration

**Field names**:
```yaml
values:
  local: 'FieldName_Local'  # Database field on this object
  parent: 'FieldName'  # Field name on parent object
  site: 'FieldName'  # Field name on site config
```

**Method names** (prefix with `->`):
```yaml
values:
  local: '->getLocalFieldName'  # Call method instead of field
  site: '->getSiteFieldName'
```

**Multiple fallbacks** (pipe-separated):
```yaml
values:
  local: '->getFieldName|FieldName_Local'  # Try method first, then field
```

**Forced inheritance** (set `{inherit}` to `true`):
```yaml
values:
  '{inherit}': true  # Force inheritance, no checkbox shown
```

When `'{inherit}': true`:
- No DoInherit checkbox shown in CMS
- Inheritance always active (automatic fallback)
- Local field still shown for user input
- No DoInherit database field needed

### Relation Configuration

**Relation names**:
```yaml
relations:
  parent: 'Parent'  # has_one relation to parent
  site: 'Site'  # has_one relation to site
```

**Method names** (prefix with `->`):
```yaml
relations:
  parent: '->getParentObject'  # Call method to get parent
  site: '->getSiteConfig'  # Call method to get site
```

**Required relations**:
```yaml
relations:
  '{require}': 'parent|site'  # These sources require relation to exist
```

### CMS Fields Configuration

**Tab path**:
```yaml
cms_fields:
  tab_path: 'Root.Main'  # Place in Main tab
```

**Field placement** (relative to another field):
```yaml
cms_fields:
  placement: 'before'  # or 'after'
  field: 'Content'  # Place before/after this field
```

**Settings fields** (for Settings tab):
```yaml
settings_fields:
  tab_path: 'Root.Settings'
```

## Usage

### In PHP

#### Getting Values

```php
// Get resourceful value (handles inheritance automatically)
$value = $page->getResourcefulValue('FieldName');

// Check if field is enabled
if ($page->isResourcefulEnabled('FieldName')) {
    // Field is enabled
}

// Check if field is inheritable
if ($page->isResourcefulInheritable('FieldName')) {
    // Field can inherit from parent
}

// Check if field is currently inheriting
if ($page->isResourcefulInherited('FieldName')) {
    // Field is inheriting from parent
}
```

#### Working with Resourceful Instance

```php
// Get Resourceful instance
$resourceful = $page->getResourceful('FieldName');

// Get current source
$source = $resourceful->getSource();  // 'local', 'parent', 'site', 'none'

// Get value from specific source
$localValue = $resourceful->getSourceValue('local');
$siteValue = $resourceful->getSourceValue('site');

// Check if source is available
if ($resourceful->isSourceAvailable('parent')) {
    // Parent source is available
}

// Get available sources
$sources = $resourceful->getAvailableSources();  // ['local', 'site', 'none']
```

### In Templates

```html
<!-- Use resourceful value -->
<% if $SidebarContent %>
    <aside>
        $SidebarContent
    </aside>
<% end_if %>

<!-- Check if inheriting -->
<% if $isResourcefulInherited('SidebarContent') %>
    <p>Inheriting from parent</p>
<% end_if %>

<!-- Conditional based on source -->
<% if $SidebarContent %>
    <% if $isResourcefulInherited('SidebarContent') %>
        <p class="inherited">Inherited content</p>
    <% else %>
        <p class="local">Local content</p>
    <% end_if %>
<% end_if %>
```

### Custom Getter Methods

Create custom getter methods for cleaner templates:

```php
public function getSidebarContent(): ?string
{
    return $this->getResourcefulValue('SidebarContent');
}

public function getSidebarArea(): ?EvoElementalArea
{
    return $this->getResourcefulValue('SidebarArea');
}
```

Then in templates:

```html
<% if $SidebarContent %>
    $SidebarContent
<% end_if %>

<% if $SidebarArea %>
    <% loop $SidebarArea.Elements %>
        $Me
    <% end_loop %>
<% end_if %>
```

## Advanced Features

### Custom Source Methods

Define custom methods to retrieve values:

```yaml
Page:
  resourceful:
    SidebarArea:
      values:
        local: '->getLocalSidebarArea'
        site: '->getSiteSidebarArea'
      relations:
        site: '->getSidebarConfig'
```

```php
public function getLocalSidebarArea(): ?EvoElementalArea
{
    return $this->SidebarArea_Local();
}

public function getSiteSidebarArea(): ?EvoElementalArea
{
    return $this->getSidebarConfig()->SidebarArea();
}

public function getSidebarConfig(): SiteConfig
{
    return SiteConfig::current_site_config();
}
```

### Custom CMS Fields

Override automatic field generation:

```php
public function getSidebarAreaCMSFields(): FieldList
{
    return FieldList::create(
        // Your custom fields
    );
}
```

### Per-Source CMS Fields

Add fields that appear only when a specific source is selected:

```php
public function getCMSFields_SidebarArea_local(
    bool $isInherited,
    ?string $selectedSource,
    bool $isDefault,
    ?string $fieldName,
    ?string $relationName
): FieldList {
    return FieldList::create(
        // Fields for local source
        HTMLEditorField::create('SidebarContent_Local', 'Sidebar Content')
    );
}

public function getCMSFields_SidebarArea_site(
    bool $isInherited,
    ?string $selectedSource,
    bool $isDefault,
    ?string $fieldName,
    ?string $relationName
): FieldList {
    return FieldList::create(
        // Fields for site source (read-only preview, etc.)
        ReadonlyField::create('SitePreview', 'Site Default',
            $this->getSiteConfig()->SidebarContent
        )
    );
}
```

### Forcing Sources

Force a specific source via configuration:

```yaml
HomePage:
  resourceful:
    SidebarArea:
      sources:
        force: 'none'  # Home page never has sidebar
```

Or force with fallback:

```yaml
Page:
  resourceful:
    SidebarArea:
      sources:
        force: 'parent|site'  # Try parent, fallback to site
```

### Forced Inheritance (No Checkbox)

Force inheritance without showing a checkbox:

```yaml
Page:
  resourceful:
    HeroHeadline:
      sources:
        inherit: 'page'
        select: 'local'
        default: 'local'
      values:
        '{inherit}': true  # Force inheritance, no checkbox
        page: 'Title'  # Inherit from Title field
```

**Result**:
- No DoInherit checkbox shown
- Only local text field shown
- Inheritance always active (automatic fallback)
- When local field is empty, uses Title field value
- No DoInherit database field needed

**Use Case**: Automatic fallback to another field without user choice (e.g., use page title as hero headline if custom headline is empty)

**Database Fields**:
```php
private static $db = [
    'HeroHeadline_Local' => 'Varchar(255)',  // Only local field needed
];
```

**Comparison with Optional Inheritance**:

```yaml
# Optional inheritance (shows checkbox)
HeroLede:
  values:
    '{inherit}': 'HeroLede_DoInherit'  # Field name = checkbox shown
    page: 'Lede'

# Forced inheritance (no checkbox)
HeroHeadline:
  values:
    '{inherit}': true  # Boolean true = no checkbox
    page: 'Title'
```

### Disabling Auto-Placement

Disable automatic CMS field placement:

```yaml
Page:
  do_auto_place_resourceful_cms_fields: false
```

Then manually place fields:

```php
public function getCMSFields(): FieldList
{
    $fields = parent::getCMSFields();

    // Manually place resourceful fields
    $resourceful = $this->getResourceful('SidebarArea');
    $fields = $resourceful->placeCMSFields($fields);

    return $fields;
}
```

### Multiple Resourceful Fields

Configure multiple fields at once:

```yaml
Page:
  resourceful:
    SidebarArea:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
        default: 'site'
      cms_fields:
        tab_path: 'Root.Sidebar'

    FooterArea:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
        default: 'site'
      cms_fields:
        tab_path: 'Root.Footer'

    HeaderImage:
      sources:
        inherit: 'parent'
        select: 'local|none'
        default: 'local'
      cms_fields:
        tab_path: 'Root.Main'
```

### Multisite Support

Resourceful automatically detects multisite modules:

```yaml
Page:
  resourceful:
    SidebarArea:
      relations:
        site: 'Site'  # Automatically uses Site relation if multisite installed
```

If multisite is not installed, falls back to `SiteConfig::current_site_config()`.

## API Reference

### ResourcefulExtension

Applied to DataObjects that use resourceful fields.

#### Methods

```php
// Get Resourceful instance
public function getResourceful(string $name): Resourceful

// Check if enabled
public function isResourcefulEnabled(string $name): bool

// Get value
public function getResourcefulValue(string $name)

// Check if inheritable
public function isResourcefulInheritable(string $name): bool

// Check if inherited
public function isResourcefulInherited(string $name): bool

// Get source field options
public function getResourcefulSourceFieldOptions(string $name): ?array
```

### Resourceful Class

Core class that handles resourceful logic.

#### Factory Methods

```php
// Create instance
public static function inst(DataObject $dObj, string $name): Resourceful

// Get all resourceful field names
public static function getAllNames(DataObject $dObj): ?array

// Set defaults for all fields
public static function setAllFieldDefaults(DataObject $dObj): void

// Place all CMS fields
public static function placeAllCMSFields(FieldList $fields, DataObject $dObj): FieldList

// Place all settings fields
public static function placeAllSettingsFields(FieldList $fields, DataObject $dObj): FieldList
```

#### Configuration Methods

```php
// Get configuration data
public function getConfigData(): ?array

// Get specific config value
public function getConfigValue(string $key, ?array $data = null)

// Check if enabled
public function isEnabled(): bool

// Get field names
public function getFieldName(): string
public function getDoInheritFieldName(): ?string
public function getSourceFieldName(): ?string
```

#### Source Methods

```php
// Get current source
public function getSource(): ?string

// Get value
public function getValue()

// Check inheritance
public function isInheritable(): bool
public function isInherited(): bool

// Get specific sources
public function getInheritSource(): ?string
public function getDefaultSource(): ?string
public function getForceSource(): ?string
public function getSelectSources(): ?array
public function getSelectedSource(): ?string

// Get value from source
public function getSourceValue(?string $source)

// Get relation for source
public function getSourceRelation(?string $source): ?DataObject

// Check source availability
public function isSourceAvailable(?string $source): bool
public function getAvailableSources(): ?array
```

#### CMS Field Methods

```php
// Get CMS fields
public function getCMSFields(): ?FieldList
public function getDoInheritCMSField(): ?CheckboxFieldGroup
public function getSourceCMSField(): ?FormField
public function getCMSFieldsForSource(string $source): ?FieldList

// Place CMS fields
public function placeCMSFields(FieldList $fields, bool $doRemoveFields = false): FieldList
public function placeSettingsFields(FieldList $fields, bool $doRemoveFields = false): FieldList
public function removeCMSFields(FieldList $fields): FieldList
```

## Real-World Examples

### Example 1: Inheritable Sidebar

```yaml
Page:
  extensions:
    - Fromholdio\Resourceful\Extensions\ResourcefulExtension

  resourceful:
    SidebarArea:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
        default: 'site'
      relations:
        site: '->getSidebarConfig'
      cms_fields:
        tab_path: 'Root.Sidebar'
```

```php
class Page extends SiteTree
{
    private static $db = [
        'SidebarArea_DoInherit' => 'Boolean',
        'SidebarArea_Source' => 'Varchar(10)',
    ];

    private static $has_one = [
        'SidebarArea_Local' => SidebarElementalArea::class,
    ];

    private static $defaults = [
        'SidebarArea_DoInherit' => true,
        'SidebarArea_Source' => 'default',
    ];

    public function getSidebarArea(): ?EvoElementalArea
    {
        return $this->getResourcefulValue('SidebarArea');
    }

    public function getSidebarConfig(): SiteConfig
    {
        return SiteConfig::current_site_config();
    }
}
```

```yaml
SilverStripe\SiteConfig\SiteConfig:
  extensions:
    - Fromholdio\Resourceful\Extensions\ResourcefulExtension

  has_one:
    SidebarArea: SidebarElementalArea
```

**Result**: Pages can inherit sidebar from parent, use site-wide default, use custom local sidebar, or have no sidebar.

### Example 2: Header Image with Parent Inheritance

```yaml
Page:
  resourceful:
    HeaderImage:
      sources:
        inherit: 'parent'
        select: 'local|none'
        default: 'local'
      cms_fields:
        placement: 'before'
        field: 'Content'
```

```php
class Page extends SiteTree
{
    private static $db = [
        'HeaderImage_DoInherit' => 'Boolean',
        'HeaderImage_Source' => 'Varchar(10)',
    ];

    private static $has_one = [
        'HeaderImage_Local' => Image::class,
    ];

    public function getHeaderImage(): ?Image
    {
        return $this->getResourcefulValue('HeaderImage');
    }
}
```

**Result**: Pages can inherit header image from parent or use their own.

### Example 3: Multiple Areas with Resourceful

```yaml
Page:
  resourceful:
    SideArea:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
        default: 'site'
      relations:
        site: '->getSideAreaConfig'
      cms_fields:
        tab_path: 'Root.SidebarTabSet.SidebarMainTab'

    BelowArea:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
        default: 'site'
      relations:
        site: '->getBelowAreaConfig'
      cms_fields:
        tab_path: 'Root.FooterTabSet.FooterMainTab'
```

```php
class Page extends SiteTree
{
    private static $db = [
        'SideArea_DoInherit' => 'Boolean',
        'SideArea_Source' => 'Varchar(10)',
        'BelowArea_DoInherit' => 'Boolean',
        'BelowArea_Source' => 'Varchar(10)',
    ];

    private static $has_one = [
        'SideArea_Local' => SideElementalArea::class,
        'BelowArea_Local' => BlocksElementalArea::class,
    ];

    public function getSideArea(): ?EvoElementalArea
    {
        return $this->getResourcefulValue('SideArea');
    }

    public function getBelowArea(): ?EvoElementalArea
    {
        return $this->getResourcefulValue('BelowArea');
    }

    public function getSideAreaConfig(): SiteConfig
    {
        return SiteConfig::current_site_config();
    }

    public function getBelowAreaConfig(): SiteConfig
    {
        return SiteConfig::current_site_config();
    }
}
```

**Result**: Both sidebar and footer areas can independently inherit from parent or use site defaults.

### Example 4: Forced Inheritance for Hero Fields

```yaml
Page:
  resourceful:
    HeroHeadline:
      sources:
        inherit: 'page'
        select: 'local'
        default: 'local'
      values:
        '{inherit}': true  # Forced inheritance
        page: 'Title'
      cms_fields:
        tab_path: 'Root.HeroTabSet.HeroMainTab'

    HeroLede:
      sources:
        inherit: 'page'
        select: 'local'
        default: 'local'
      values:
        '{inherit}': 'HeroLede_DoInherit'  # Optional inheritance
        page: 'Lede'
      cms_fields:
        tab_path: 'Root.HeroTabSet.HeroMainTab'
```

```php
class Page extends SiteTree
{
    private static $db = [
        'HeroHeadline_Local' => 'Varchar(255)',  // No DoInherit field
        'HeroLede_DoInherit' => 'Boolean',       // Has DoInherit field
        'HeroLede_Local' => 'Text',
    ];

    public function getHeroHeadline(): string
    {
        return $this->getResourcefulValue('HeroHeadline');
    }

    public function getHeroLede(): string
    {
        return $this->getResourcefulValue('HeroLede');
    }
}
```

**Result**:
- **HeroHeadline**: Always falls back to page Title if empty (no checkbox)
- **HeroLede**: User can choose to inherit from page Lede or use custom (checkbox shown)

## License

BSD-3-Clause

## Support

- **GitHub**: https://github.com/fromholdio/silverstripe-resourceful
- **Issues**: https://github.com/fromholdio/silverstripe-resourceful/issues

## Contributing

Contributions are welcome! Please open an issue or pull request on GitHub.
