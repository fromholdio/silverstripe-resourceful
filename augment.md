# Resourceful Module - AI Technical Deep Dive

This document provides comprehensive technical details about the Resourceful module for AI assistants. It covers architecture, implementation mechanics, patterns, edge cases, and debugging strategies.

## Architecture Overview

### Core Components

1. **Resourceful Class** (`src/Resourceful.php`)
   - Singleton-based factory pattern
   - Configuration-driven behavior
   - Handles value retrieval and source resolution
   - Generates CMS fields automatically

2. **ResourcefulExtension** (`src/Extensions/ResourcefulExtension.php`)
   - Applied to DataObjects that use resourceful fields
   - Provides convenience methods
   - Hooks into CMS field generation
   - Sets field defaults on object creation

### Design Philosophy

**Problem Solved**: In hierarchical SilverStripe projects, implementing field inheritance requires repetitive boilerplate:
- Database fields for inheritance flags and source selection
- Getter methods with traversal logic
- CMS fields with display logic
- Consistent UX across all inheritable fields

**Solution**: Configuration-driven approach where YAML defines:
- Available sources (local, parent, site, custom)
- Inheritance behavior
- Field placement
- Value retrieval methods

**Key Insight**: By abstracting the inheritance pattern into configuration, Resourceful eliminates 90% of boilerplate code while providing consistent UX.

## Configuration System

### Configuration Structure

```yaml
DataObject:
  resourceful:
    FieldName:
      enabled: true|false
      sources:
        force: null|'source'|['source1', 'source2']
        inherit: null|'source'
        select: 'source1|source2|source3'
        default: 'source'
      values:
        source: 'field_name'|'->method_name'|['->method', 'field']
        '{inherit}': 'DoInheritFieldName'
        '{source}': 'SourceFieldName'
      relations:
        source: 'RelationName'|'->method_name'
        '{require}': 'source1|source2'
      source_field_class: 'FormFieldClass'
      cms_fields:
        tab_path: 'Root.Tab'
        placement: 'before'|'after'
        field: 'FieldName'
      settings_fields:
        tab_path: 'Root.Settings'
```

### Configuration Merging

**Default Configuration** (`Resourceful::$default_config`):
```php
[
    'enabled' => true,
    'sources' => [
        'force' => null,
        'inherit' => null,
        'select' => self::SOURCE_LOCAL,
        'default' => self::SOURCE_LOCAL
    ],
    'values' => [
        self::SOURCE_LOCAL => '->{field_name}_Local|{field_name}_Local',
        '{inherit}' => '{field_name}_DoInherit',
        '{source}' => '{field_name}_Source'
    ],
    'relations' => [
        self::SOURCE_PARENT => 'Parent',
        self::SOURCE_SITE => 'Site',
        '{require}' => self::SOURCE_PARENT .'|' .self::SOURCE_SITE
    ],
    'source_field_class' => OptionsetField::class,
    'cms_fields' => null,
    'settings_fields' => null
]
```

**Merge Process**:
1. Get default config with `{field_name}` placeholders replaced
2. Get named config from DataObject's `resourceful` config
3. Merge arrays recursively (named config overrides defaults)
4. Parse pipe-separated strings into arrays
5. Cache merged config per field name

**Key Methods**:
- `getDefaultConfigData()` - Returns defaults with placeholders replaced
- `mergeWithDefaultConfigData($namedData)` - Merges named with defaults
- `getConfigData()` - Returns cached merged config
- `getConfigValue($key)` - Gets value with dot notation support

### Configuration Caching

**Cache Strategy**:
- Cached per Resourceful instance in `$namedConfigs` array
- Cache key is field name
- Cache cleared when DataObject or name changes
- No persistent caching (regenerated per request)

**Why No Persistent Cache**: Configuration is lightweight and merging is fast. Per-request caching prevents stale config issues.

## Source Resolution System

### Source Types

**Built-in Sources**:
- `SOURCE_LOCAL` = 'local' - Value from dedicated local storage field (e.g., `FieldName_Local`)
- `SOURCE_PARENT` = 'parent' - Inherited from parent object via relation traversal
- `SOURCE_SITE` = 'site' - Site-wide default via relation traversal
- `SOURCE_DEFAULT` = 'default' - Placeholder that resolves to configured default source
- `SOURCE_NONE` = 'none' - No value (null)

**Custom Sources**: Any string can be a source if configured in `values` or `relations`.

### Understanding Source Semantics

**Important Distinction**: Source names describe **where the value comes from**, not necessarily **which object** it's on.

| Source | Object | Field/Method | Traversal |
|--------|--------|--------------|-----------|
| `'local'` | Current object | `FieldName_Local` field | None |
| `'parent'` | Parent object | `FieldName` field/method | Via Parent relation |
| `'site'` | Site object | `FieldName` field/method | Via Site relation |
| `'page'` (custom) | Current object | Custom field (e.g., `Title`) | None |

**Key Insight**: Both `'local'` and custom sources like `'page'` get values from the **current object**, but from **different fields**:
- `'local'` = the dedicated storage field for custom values
- `'page'` (custom) = a different existing field on the same object

**SOURCE_DEFAULT Behavior**:
- Not a real source - it's a **placeholder/alias**
- When user selects "Default" in CMS, the `FieldName_Source` field stores `'default'`
- `getSourceValue()` resolves `'default'` to the actual configured default source
- Allows changing default source in config without database migration

### Source Resolution Flow

```
getSource()
├─ getForceSource() - Check if source is forced
│  └─ If forced, return forced source
├─ isInherited() - Check if inheriting
│  └─ If inheriting, return getInheritSource()
└─ getSelectedSource() - Get user-selected source
   └─ If null or unavailable, return getDefaultSource()
```

**Key Methods**:

1. **`getSource(): ?string`**
   - Returns the current active source
   - Respects force > inherit > selected > default priority

2. **`getForceSource(): ?string`**
   - Returns forced source from config
   - Supports array of sources (tries each until available)
   - Returns null if not forced

3. **`getInheritSource(): ?string`**
   - Returns source to inherit from (usually 'parent')
   - Only used when `isInherited()` returns true

4. **`getSelectedSource(): ?string`**
   - Returns user-selected source from `{source}` field
   - Returns null if field empty or set to 'default'

5. **`getDefaultSource(): ?string`**
   - Returns configured default source
   - Fallback when no source selected

### Source Availability

**`isSourceAvailable(string $source): bool`**

Checks if a source can be used:

1. **Special sources**: 'none' and 'default' are always available
2. **Field sources**: Check if field exists via `getFieldNameForSource()`
3. **Method sources**: Check if method exists via `getMethodNameForSource()`
4. **Relation sources**:
   - If required (in `{require}`), check relation exists
   - Otherwise, check relation name/method exists
5. **Site source**: Check if `getFallbackSite()` returns object

**Required Relations** (`{require}` config):
- Sources listed in `relations.{require}` must have existing relation
- Prevents selecting source when relation doesn't exist
- Example: Parent source requires Parent relation to exist

## Value Retrieval System

### Value Resolution Flow

```
getValue()
└─ getSource() - Determine active source
   └─ getSourceValue(source)
      ├─ Check for method via getMethodNameForSource()
      │  └─ Call method if exists
      ├─ Check for field via getFieldNameForSource()
      │  └─ Get field value if exists
      └─ Check for relation via getSourceRelation()
         └─ Recursively call getValue() on relation
```

**Key Methods**:

1. **`getValue()`**
   - Main entry point for value retrieval
   - Returns null if not enabled
   - Delegates to `getSourceValue()`

2. **`getSourceValue(?string $source)`**
   - Retrieves value from specific source
   - Handles 'default' and 'none' special cases
   - **Tries method > field > relation in order** (this order is critical!)
   - Recursively traverses relations

3. **`getMethodNameForSource(string $source)`**
   - Returns method name for source from `values` config
   - Methods prefixed with `->` in config
   - Checks if method exists on DataObject
   - Returns null if no method configured/exists

4. **`getFieldNameForSource(string $source)`**
   - Returns field name for source from `values` config
   - Fields are plain strings (no `->` prefix)
   - Checks if field exists and is not a relation
   - Returns null if no field configured/exists

5. **`getSourceRelation(?string $source): ?DataObject`**
   - Returns related object for source
   - Tries relation method > relation name > site fallback
   - Returns null if relation doesn't exist
   - Used for recursive value retrieval

### Critical: Method > Field > Relation Priority

**The order in `getSourceValue()` is crucial**:

1. **First**: Check for method mapping in `values` config
   - If found and method exists, call it on **current object**
   - Return the result

2. **Second**: Check for field mapping in `values` config
   - If found and field exists, get it from **current object**
   - Return the value

3. **Third**: Check for relation mapping in `relations` config
   - If found and relation exists, **traverse to related object**
   - Create new Resourceful instance for related object
   - Recursively call `getValue()` on it
   - Return the result

**Why This Matters**:
- `values` config = get from **current object** (no traversal)
- `relations` config = get from **different object** (traversal)
- If both are configured for same source, `values` takes precedence

### Fallback Chains

**Multiple Value Options** (pipe-separated):
```yaml
values:
  local: '->getLocalValue|LocalValue_Field|->fallbackMethod'
```

**Resolution Order**:
1. Try `->getLocalValue()` method
2. Try `LocalValue_Field` field
3. Try `->fallbackMethod()` method
4. Return null if all fail

**Multiple Relation Options**:
```yaml
relations:
  parent: '->getParentObject|Parent|->fallbackParent'
```

**Resolution Order**:
1. Try `->getParentObject()` method
2. Try `Parent` relation
3. Try `->fallbackParent()` method
4. Return null if all fail

### Recursive Traversal

**Parent Inheritance**:
```php
// Page A (root)
//   └─ Page B (inherits)
//        └─ Page C (inherits)

// Page C calls getValue()
getSourceValue('parent')
└─ getSourceRelation('parent') // Returns Page B
   └─ Resourceful::inst(Page B, 'FieldName')
      └─ getValue() // Page B is also inheriting
         └─ getSourceValue('parent')
            └─ getSourceRelation('parent') // Returns Page A
               └─ Resourceful::inst(Page A, 'FieldName')
                  └─ getValue() // Page A uses local value
                     └─ getSourceValue('local')
                        └─ Returns Page A's local value
```

**Recursion Prevention**: None needed - recursion naturally terminates when:
- Object doesn't inherit (uses local/site/none)
- Parent relation doesn't exist
- Relation returns null

## Inheritance System

### Inheritance Mechanics

**Inheritance Enabled When**:
1. `isInheritable()` returns true:
   - Inherit config is set (either field name OR `true`)
   - Inherit source configured
   - Inherit source is available
   - If field name: DataObject has DoInherit field
   - If `true`: No field check needed (forced)

2. `isInherited()` returns true:
   - If forced (`'{inherit}': true`): Always true when inheritable
   - If not forced: `isInheritable()` is true AND DoInherit field value is true

**Inheritance Flow (Optional Checkbox)**:
```
User checks "Inherit from parent" checkbox
└─ DoInherit field set to true
   └─ getSource() returns getInheritSource()
      └─ getValue() calls getSourceValue('parent')
         └─ Traverses to parent and recursively calls getValue()
```

**Inheritance Flow (Forced)**:
```
No checkbox shown ('{inherit}': true)
└─ isInherited() always returns true
   └─ getSource() returns getInheritSource()
      └─ getValue() calls getSourceValue('parent')
         └─ Traverses to parent and recursively calls getValue()
```

### DoInherit Field

**Purpose**: Boolean field that enables/disables inheritance (when not forced)

**Field Name**: Configured in `values.{inherit}`, defaults to `{FieldName}_DoInherit`

**Special Value**: Set to `true` (boolean) to force inheritance without checkbox

**Behavior**:
- **When field name** (e.g., `'HeroLede_DoInherit'`):
  - Shows checkbox in CMS
  - When checked: Use inherit source (usually parent)
  - When unchecked: Use selected or default source
  - Overrides source selection when checked
- **When `true` (boolean)**:
  - No checkbox shown in CMS
  - Inheritance always active
  - Local field still shown for user input
  - Acts as automatic fallback when local is empty

**CMS Field**: CheckboxFieldGroup with label from `fieldLabel()` (only when field name configured)

### Inheritance vs Source Selection

**Key Distinction**:
- **Inheritance**: Binary on/off via checkbox
- **Source Selection**: Choose between available sources

**Interaction**:
- When inheriting: Source selection hidden (display logic)
- When not inheriting: Source selection visible
- Inheritance takes precedence over source selection

**Example**:
```
[ ] Inherit from parent
    ○ Use site default
    ○ Use custom content
    ○ No sidebar
```

When checkbox checked:
```
[✓] Inherit from parent
    (source selection hidden)
```

## CMS Field Generation

### Field Generation Flow

```
getCMSFields()
├─ Check if enabled
├─ Check for custom method (get{FieldName}CMSFields)
├─ Generate DoInherit field (if inheritable)
├─ Generate Source field
│  ├─ Get available sources
│  ├─ Create OptionsetField or HiddenField
│  └─ Wrap in DisplayLogic wrapper
├─ Generate per-source fields
│  └─ Call getCMSFields_{FieldName}_{source}() for each source
└─ Return FieldList
```

### DoInherit Field

**Generated When**: `isInheritable()` returns true

**Field Type**: InheritedSourcesField wrapping CheckboxFieldGroup

**Field Name**: From `getDoInheritFieldName()`

**Labels**: From `fieldLabel()` on DataObject

**Display Logic**: None (always visible when inheritable)

**Inherited Value Display** (New Feature):
- Calls `getInheritedValue()` to get the value that would be inherited
- Checks for custom method: `getCMSField_{FieldName}_InheritedValue($fieldName, $value)`
- If method exists, calls it to generate a readonly field showing the inherited value
- Field is wrapped in DisplayLogic to show only when DoInherit is checked
- Allows users to see what value they'll get before checking the inherit checkbox

**Example Implementation**:
```php
public function getCMSField_HeroHeadline_InheritedValue(
    string $fieldName,
    mixed $value
): ReadonlyField {
    return ReadonlyField::create(
        $fieldName,
        $this->fieldLabel($fieldName),
        $value
    );
}
```

**Method Signature**:
- `$fieldName`: The field name to use (e.g., `HeroHeadline_DoInherit_Value`)
- `$value`: The inherited value from `getInheritedValue()`
- Returns: FormField (typically ReadonlyField or TextareaField with readonly=true)

**getInheritedValue() Implementation**:
```php
public function getInheritedValue(): mixed
{
    if (!$this->isInherited()) {
        return '';
    }
    $source = $this->getInheritSource();
    return $this->getSourceValue($source);
}
```

**Why This Works**:
- Simply delegates to `getSourceValue()` which already handles both:
  - Field-based sources (gets from current object)
  - Relation-based sources (traverses to related object)
- No need for special logic or relation traversal
- Works for all source types (parent, site, custom fields, etc.)

### Source Field

**Generated When**: Source field name configured and sources available

**Field Type**:
- **HiddenField**: When only one source available
- **OptionsetField**: When multiple sources available (default)
- **Custom**: Via `source_field_class` config

**Field Name**: From `getSourceFieldName()`

**Options**: From `getSourceCMSFieldOptions()`
- Maps source keys to labels
- Default source mapped to 'default' key
- Labels from `fieldLabel({SourceFieldName}_{source})`

**Display Logic**: Hidden when DoInherit is checked

### Per-Source Fields

**Method Convention**: `getCMSFields_{FieldName}_{source}()`

**Method Signature**:
```php
public function getCMSFields_{FieldName}_{source}(
    bool $isInherited,
    ?string $selectedSource,
    bool $isDefault,
    ?string $fieldName,
    ?string $relationName
): FieldList|FormField
```

**Parameters**:
- `$isInherited` - Whether currently inheriting
- `$selectedSource` - User-selected source (null if default)
- `$isDefault` - Whether this source is the default
- `$fieldName` - Field name for this source (from values config)
- `$relationName` - Relation name for this source (from relations config)

**Display Logic**: Shown when source field equals this source

**Example**:
```php
public function getCMSFields_SidebarArea_local(
    bool $isInherited,
    ?string $selectedSource,
    bool $isDefault,
    ?string $fieldName,
    ?string $relationName
): FieldList {
    return FieldList::create(
        HTMLEditorField::create('SidebarContent_Local', 'Sidebar Content')
    );
}
```

### Field Placement

**Auto-Placement** (default):
- Enabled via `do_auto_place_resourceful_cms_fields` config (default true)
- Hooks into `updateCMSFields()`, `updateSiteCMSFields()`, `updateSettingsFields()`
- Uses fromholdio/silverstripe-cms-fields-placement for placement

**Manual Placement**:
```php
$resourceful = $this->getResourceful('FieldName');
$fields = $resourceful->placeCMSFields($fields);
```

**Placement Config**:
```yaml
cms_fields:
  tab_path: 'Root.Main'  # Place in tab
  placement: 'before'    # before/after
  field: 'Content'       # Relative to this field
```

## Field Defaults

### Default Values

**Set When**: `onAfterPopulateDefaults()` hook

**Values Set**:
- DoInherit field: `true`
- Source field: `'default'`

**Method**: `Resourceful::setAllFieldDefaults()`

**Why**: Ensures new objects have sensible defaults (inherit by default)

### Default Source Handling

**'default' Value**: Special value in Source field

**Resolution**: When Source field is 'default', `getSelectedSource()` returns null, causing `getDefaultSource()` to be used

**Why Not Direct Value**: Allows changing default source in config without database migration

## Integration Patterns

### Elemental Base Integration

**Pattern**: Use Resourceful for Current vs Local elemental areas

**Configuration**:
```yaml
Page:
  elemental_areas:
    SidebarArea:
      has_one: 'SidebarArea_Local'  # Local storage
      current: 'getResourcefulArea'  # Current retrieval

  resourceful:
    SidebarArea:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
```

**Implementation**:
```php
public function getResourcefulArea(string $name): ?EvoElementalArea
{
    return $this->getResourcefulValue($name);
}
```

**Result**: Elemental areas can inherit from parent or use site defaults

### Multisite Integration

**Detection**: Checks for multisite modules via ModuleLoader

**Modules Detected**:
- `symbiote/silverstripe-multisites`
- `fromholdio/silverstripe-configured-multisites`

**Behavior**:
- If multisite: Use `Site` relation
- If not: Use `SiteConfig::current_site_config()`

**Method**: `getFallbackSite()`

## Edge Cases & Gotchas

### 1. Circular Inheritance

**Scenario**: Page A inherits from Page B, Page B inherits from Page A

**Prevention**: None built-in

**Result**: Infinite recursion, PHP fatal error

**Solution**: Don't create circular parent relationships (SilverStripe prevents this at tree level)

### 1a. Same-Object Circular Reference (Common Mistake!)

**Scenario**: Configuring a custom source to inherit from the same object via relation

**Example of Wrong Config**:
```yaml
Page:
  resourceful:
    HeroHeadline:
      sources:
        inherit: 'page'
      relations:
        page: '->getCurrentPage'  # Returns $this->owner
```

```php
public function getCurrentPage(): SiteTree
{
    return $this->owner;  // Same object!
}
```

**What Happens**:
1. `getSourceValue('page')` finds relation mapping
2. Calls `getCurrentPage()` → returns same object
3. Creates new Resourceful for same object with same field name
4. Calls `getValue()` → still inheriting
5. Calls `getSourceValue('page')` again
6. **Infinite loop!**

**Error**: `Xdebug has detected a possible infinite loop, and aborted your script with a stack depth of '512' frames`

**The Fix**: Use `values` config instead of `relations`:
```yaml
Page:
  resourceful:
    HeroHeadline:
      sources:
        inherit: 'page'
      values:
        page: 'Title'  # Get Title field from current object
```

**Why This Works**:
- `values` config → `getSourceValue()` calls `$page->getField('Title')` on current object
- No relation traversal, no new Resourceful instance, no recursion
- Returns the field value directly

**Rule of Thumb**:
- Use `values` when getting from **current object** (different field)
- Use `relations` when getting from **different object** (traversal)

### 2. Missing Relations

**Scenario**: Source configured but relation doesn't exist

**Behavior**: `isSourceAvailable()` returns false, source not selectable

**Prevention**: Use `{require}` config to mark sources that need relations

### 3. Type Mismatches

**Scenario**: Field expects Image but method returns string

**Behavior**: Type error or unexpected behavior

**Prevention**: Ensure value types match across inheritance chain

### 4. Null vs Empty

**Scenario**: Distinguishing between "no value" and "empty value"

**Behavior**: Both return null from `getValue()`

**Solution**: Use 'none' source explicitly for "no value"

### 5. Default Source Changes

**Scenario**: Change default source in config after objects created

**Behavior**: Objects with Source='default' automatically use new default

**Benefit**: No database migration needed

### 6. Force Source with Unavailable Source

**Scenario**: `force: 'parent'` but no parent exists

**Behavior**: `getForceSource()` returns 'parent', `getSourceValue()` returns null

**Solution**: Use array for force: `force: ['parent', 'site']`

### 7. Method vs Field Priority

**Scenario**: Both method and field configured for same source

**Behavior**: Method takes priority (checked first)

**Why**: Methods can contain logic, fields are just data

### 8. Relation Recursion Depth

**Scenario**: Deep inheritance chain (10+ levels)

**Behavior**: Works but may be slow

**Optimization**: None built-in, consider caching

## Debugging Strategies

### 1. Trace Source Resolution

```php
$resourceful = $page->getResourceful('FieldName');
$source = $resourceful->getSource();
$value = $resourceful->getSourceValue($source);

// Check each step
$forced = $resourceful->getForceSource();
$inherited = $resourceful->isInherited();
$selected = $resourceful->getSelectedSource();
$default = $resourceful->getDefaultSource();
```

### 2. Check Configuration

```php
$config = $resourceful->getConfigData();
var_dump($config);

// Check specific values
$sources = $resourceful->getConfigValue('sources');
$values = $resourceful->getConfigValue('values');
$relations = $resourceful->getConfigValue('relations');
```

### 3. Test Source Availability

```php
$sources = $resourceful->getSelectSources();
foreach ($sources as $source) {
    $available = $resourceful->isSourceAvailable($source);
    echo "$source: " . ($available ? 'available' : 'unavailable') . "\n";
}
```

### 4. Trace Relation Traversal

```php
$relation = $resourceful->getSourceRelation('parent');
if ($relation) {
    $parentResourceful = Resourceful::inst($relation, 'FieldName');
    $parentValue = $parentResourceful->getValue();
}
```

### 5. Check Field Names

```php
$fieldName = $resourceful->getFieldNameForSource('local');
$methodName = $resourceful->getMethodNameForSource('local');
$relationName = $resourceful->getRelationNameForSource('parent');
```

## Performance Considerations

### Configuration Caching

**Current**: Per-request caching in `$namedConfigs`

**Impact**: Minimal - config merging is fast

**Optimization**: Not needed

### Value Caching

**Current**: No caching

**Impact**: Each `getValue()` call traverses relations

**Optimization**: Consider caching in DataObject if called frequently

**Example**:
```php
private $cachedResourcefulValues = [];

public function getSidebarArea(): ?EvoElementalArea
{
    if (!isset($this->cachedResourcefulValues['SidebarArea'])) {
        $this->cachedResourcefulValues['SidebarArea'] =
            $this->getResourcefulValue('SidebarArea');
    }
    return $this->cachedResourcefulValues['SidebarArea'];
}
```

### Relation Traversal

**Current**: Recursive traversal on each call

**Impact**: Deep hierarchies may be slow

**Optimization**: Cache at DataObject level or use partial caching

### CMS Field Generation

**Current**: Generated on each CMS load

**Impact**: Minimal - field generation is fast

**Optimization**: Not needed

## Common Patterns

### Pattern 1: Simple Inheritance

```yaml
Page:
  resourceful:
    HeaderImage:
      sources:
        inherit: 'parent'
        select: 'local|none'
```

**Use Case**: Simple parent inheritance with local override

### Pattern 2: Site Default with Parent Inheritance

```yaml
Page:
  resourceful:
    SidebarArea:
      sources:
        inherit: 'parent'
        select: 'site|local|none'
        default: 'site'
```

**Use Case**: Most pages use site default, some inherit from parent, some customize

### Pattern 3: Forced Source

```yaml
HomePage:
  resourceful:
    SidebarArea:
      sources:
        force: 'none'
```

**Use Case**: Specific page types never have certain features

### Pattern 4: Custom Source Method

```yaml
Page:
  resourceful:
    SidebarArea:
      values:
        site: '->getSitebarConfig'
      relations:
        site: '->getSidebarConfigObject'
```

**Use Case**: Complex logic for retrieving site defaults

### Pattern 5: Inherit from Current Object Field

```yaml
Page:
  resourceful:
    HeroHeadline:
      sources:
        inherit: 'page'
        select: 'local'
        default: 'local'
      values:
        page: 'Title'  # Get from Title field on current object
    HeroLede:
      sources:
        inherit: 'page'
        select: 'local'
        default: 'local'
      values:
        page: 'Lede'  # Get from Lede field on current object
```

**Use Case**: Allow users to "inherit" from a different field on the same object (e.g., use page title as hero headline)

**How It Works**:
- When `DoInherit=true`, source becomes `'page'`
- `getSourceValue('page')` checks for field mapping
- Finds `'page' => 'Title'` in `values` config
- Calls `$page->getField('Title')` on **current object**
- No relation traversal, no recursion

**Common Mistake**: Configuring this as a relation instead of a value:
```yaml
# ❌ WRONG - Creates infinite loop!
relations:
  page: '->getCurrentPage'  # Returns $this->owner

# ✅ CORRECT - Gets field from current object
values:
  page: 'Title'
```

**Why Wrong Config Causes Infinite Loop**:
1. `getSourceValue('page')` finds relation mapping
2. Calls `getCurrentPage()` which returns same object
3. Creates new Resourceful for same object
4. Calls `getValue()` which checks if inheriting
5. Still inheriting, so calls `getSourceValue('page')` again
6. Infinite recursion!

**The Fix**: Use `values` config for same-object fields, `relations` config only for different objects.

### Pattern 6: Forced Inheritance (No Checkbox)

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
        page: 'Title'
    HeroLede:
      sources:
        inherit: 'page'
        select: 'local'
        default: 'local'
      values:
        '{inherit}': 'HeroLede_DoInherit'  # Optional checkbox
        page: 'Lede'
```

**Use Case**: Automatic fallback to inherited value without user choice (e.g., always use page title as hero headline if custom headline is empty)

**How It Works**:
- `'{inherit}': true` signals forced inheritance
- `isInheritForced()` returns true
- `isInherited()` always returns true (when inheritable)
- No DoInherit checkbox shown in CMS
- Local field still shown for user input
- Acts as automatic fallback when local is empty

**UI Behavior**:
- **HeroHeadline**: Only shows local text field, no checkbox
- **HeroLede**: Shows checkbox + inherited value display + local text field

**Database Fields Needed**:
```php
private static $db = [
    'HeroHeadline_Local' => 'Varchar(255)',  // No DoInherit field needed
    'HeroLede_DoInherit' => 'Boolean',       // DoInherit field needed
    'HeroLede_Local' => 'Varchar(255)',
];
```

**Why This Is Useful**:
- Cleaner UI when inheritance is the obvious default
- Reduces user confusion (no checkbox to understand)
- Still allows local override via the local field
- Automatic fallback behavior without explicit user action

**Implementation Details**:
- `isInheritable()` checks if `'{inherit}'` is `true` OR a valid field name
- If `true`: Skips field existence check (no field needed)
- If field name: Checks if field exists in database
- `isInheritCMSFieldEnabled()` returns false when forced (no checkbox)
- `setFieldDefaults()` skips setting DoInherit field when forced
- `removeCMSFields()` skips removing DoInherit field when forced

### Pattern 7: Multiple Fallbacks

```yaml
Page:
  resourceful:
    SidebarArea:
      sources:
        force: ['parent', 'site', 'none']
```

**Use Case**: Try parent, fallback to site, fallback to none

## Future Considerations

### Potential Enhancements

1. **Value Caching**: Built-in caching for getValue() results
2. **Lazy Loading**: Defer relation traversal until needed
3. **Event Hooks**: Extension points for custom logic
4. **Validation**: Validate configuration on dev/build
5. **Debug Mode**: Verbose logging of source resolution
6. **Performance Profiling**: Track traversal depth and time
7. **GraphQL Support**: Expose resourceful values via GraphQL
8. **REST API**: Expose resourceful values via REST API

### Backward Compatibility

**Current Version**: 2.x

**Breaking Changes**: None planned

**Deprecations**: None

**Migration Path**: N/A

## Testing Strategies

### Unit Testing

**Test Configuration Merging**:
```php
$resourceful = Resourceful::inst($page, 'FieldName');
$config = $resourceful->getConfigData();
$this->assertEquals('parent', $config['sources']['inherit']);
```

**Test Source Resolution**:
```php
$page->FieldName_DoInherit = true;
$source = $resourceful->getSource();
$this->assertEquals('parent', $source);
```

**Test Value Retrieval**:
```php
$parent->FieldName_Local = 'Parent Value';
$page->FieldName_DoInherit = true;
$value = $page->getResourcefulValue('FieldName');
$this->assertEquals('Parent Value', $value);
```

### Integration Testing

**Test CMS Field Generation**:
```php
$fields = $page->getCMSFields();
$this->assertNotNull($fields->dataFieldByName('FieldName_DoInherit'));
$this->assertNotNull($fields->dataFieldByName('FieldName_Source'));
```

**Test Inheritance Chain**:
```php
$grandparent->FieldName_Local = 'Grandparent';
$parent->FieldName_DoInherit = true;
$page->FieldName_DoInherit = true;
$value = $page->getResourcefulValue('FieldName');
$this->assertEquals('Grandparent', $value);
```

### Functional Testing

**Test User Workflow**:
1. Create page
2. Check "Inherit from parent"
3. Verify value matches parent
4. Uncheck inheritance
5. Select "Use site default"
6. Verify value matches site
7. Select "Use custom"
8. Enter custom value
9. Verify custom value used

## Summary

Resourceful is a configuration-driven inheritance system that:

1. **Eliminates Boilerplate**: Replaces repetitive inheritance code with YAML config
2. **Provides Consistent UX**: Same interface pattern for all inheritable fields
3. **Supports Flexibility**: Multiple sources, custom methods, fallback chains
4. **Integrates Seamlessly**: Works with elemental, multisite, and custom modules
5. **Performs Well**: Lightweight with per-request caching
6. **Debugs Easily**: Clear method chain for tracing issues

**Key Takeaway**: Resourceful abstracts the inheritance pattern into configuration, making it trivial to add inheritable fields without writing boilerplate code.

## Critical Concepts for AI Assistants

### 1. Source Semantics
- Source names describe **where values come from**, not which object they're on
- `'local'` = dedicated storage field on current object
- `'parent'`/`'site'` = traverse to different object
- Custom sources can get from current object (via `values`) OR different object (via `relations`)

### 2. Values vs Relations
- **`values` config**: Get from **current object** (field or method)
- **`relations` config**: Get from **different object** (traverse and recurse)
- If both configured for same source, `values` takes precedence

### 3. Common Pitfall: Same-Object Circular Reference
- **Wrong**: Using `relations` to point to same object
- **Right**: Using `values` to get different field from same object
- **Error symptom**: Infinite loop with 512 stack frames

### 4. getInheritedValue() Simplicity
- Don't manually traverse relations
- Just call `getSourceValue($source)` - it handles everything
- Works for both field-based and relation-based sources

### 5. Inherited Value Display
- New feature: Show inherited value in readonly field
- Implement `getCMSField_{FieldName}_InheritedValue($fieldName, $value)`
- Field appears when DoInherit checkbox is checked
- Helps users see what they'll get before enabling inheritance

### 6. Forced Inheritance
- Set `'{inherit}': true` to force inheritance without checkbox
- `isInheritForced()` checks if `'{inherit}'` config is boolean `true`
- `isInheritable()` accepts both field name (string) OR `true` (boolean)
- When forced: No DoInherit field needed, no checkbox shown, inheritance always active
- When not forced: DoInherit field required, checkbox shown, user controls inheritance
- `isInheritCMSFieldEnabled()` returns false when forced (suppresses checkbox)
- Guards in `setFieldDefaults()` and `removeCMSFields()` skip DoInherit field when forced
