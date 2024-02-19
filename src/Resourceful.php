<?php

namespace Fromholdio\Resourceful;

use Fromholdio\CheckboxFieldGroup\CheckboxFieldGroup;
use Fromholdio\CMSFieldsPlacement\CMSFieldsPlacement;
use Fromholdio\Resourceful\Extensions\ResourcefulDataExtension;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\SingleSelectField;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;
use UncleCheese\DisplayLogic\Forms\Wrapper;

class Resourceful
{
    use Configurable;
    use Extensible;
    use Injectable;

    public const SOURCE_LOCAL = 'local';
    public const SOURCE_PARENT = 'parent';
    public const SOURCE_SITE = 'site';
    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_NONE = 'none';

    protected array $namedConfigs = [];
    protected DataObject $dataObject;
    protected string $name;

    private static $default_config = [
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
    ];

    public static function inst(DataObject $dObj, string $name)
    {
        $self = static::singleton();
        $self->setDataObject($dObj);
        $self->setName($name);
        return $self;
    }

    public function setDataObject(DataObject $dObj): self
    {
        $this->dataObject = $dObj;
        $this->namedConfigs = [];
        return $this;
    }

    public function getDataObject(): DataObject
    {
        return $this->dataObject;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->namedConfigs = [];
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }


    public static function getAllNames(DataObject $dObj): ?array
    {
        $names = null;
        $config = $dObj::config()->get('resourceful');
        if (!empty($config)) {
            $names = array_keys($config);
        }
        return $names;
    }

    public static function setAllFieldDefaults(DataObject $dObj): void
    {
        $names = static::getAllNames($dObj);
        if (is_null($names)) {
            return;
        }
        $self = static::inst($dObj, $names[0]);
        foreach ($names as $name) {
            $self->setName($name);
            $self->setFieldDefaults();
        }
    }

    public function setFieldDefaults(): void
    {
        $dObj = $this->getDataObject();
        $doInheritFieldName = $this->getDoInheritFieldName();
        if ($dObj->hasField($doInheritFieldName)) {
            $dObj->setField($doInheritFieldName, true);
        }
        $sourceFieldName = $this->getSourceFieldName();
        if ($dObj->hasField($sourceFieldName)) {
            $dObj->setField($sourceFieldName, self::SOURCE_DEFAULT);
        }
    }


    public function getConfigData(): ?array
    {
        $name = $this->getName();

        $cache = $this->namedConfigs;
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $dObj = $this->getDataObject();
        $config = $dObj::config()->get('resourceful');
        $namedData = $config[$name] ?? null;
        if (!is_array($namedData)) {
            return null;
        }
        $mergedData = $this->mergeWithDefaultConfigData($namedData);

        foreach ($mergedData as $key => $value)
        {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (is_string($subValue) && str_contains($subValue, '|')) {
                        $subValue = explode('|', $subValue);
                        $mergedData[$key][$subKey] = $subValue;
                    }
                }
            }
            elseif (is_string($value) && str_contains($value, '|')) {
                $value = explode('|', $value);
                $mergedData[$key] = $value;
            }
        }

        $this->namedConfigs[$name] = $mergedData;
        return $mergedData;
    }

    public function getDefaultConfigData(): array
    {
        $dObj = $this->getDataObject();
        $defaultData = static::config()->get('default_config');
        foreach ($defaultData['values'] as $key => $value)
        {
            $newValue = str_replace('{field_name}', $this->getName(), $value);
            $defaultData['values'][$key] = $newValue;
        }
        foreach ($defaultData['relations'] as $key => $value)
        {
            $newValue = str_replace('{field_name}', $this->getName(), $value);
            unset($defaultData['relations'][$key]);
            if ($key !== self::SOURCE_LOCAL || !is_null($dObj->getRelationType($newValue))) {
                $defaultData['relations'][$key] = $newValue;
            }
        }
        return $defaultData;
    }

    public function mergeWithDefaultConfigData(array $namedData): array
    {
        $defaultData = $this->getDefaultConfigData();
        $mergedData = [];
        foreach ($defaultData as $key => $value)
        {
            if (isset($namedData[$key]))
            {
                $namedValue = $namedData[$key];
                if (is_array($value) && is_array($namedValue)) {
                    $mergedValue = array_merge($value, $namedValue);
                    $mergedData[$key] = $mergedValue;
                }
                else {
                    $mergedData[$key] = $namedValue;
                }
            }
            else {
                $mergedData[$key] = $value;
            }
        }
        return $mergedData;
    }

    public function getConfigValue(string $key, ?array $data = null)
    {
        if (is_null($data)) {
            $data = $this->getConfigData();
        }
        if (is_null($data) || empty($key)) {
            return null;
        }

        if (str_contains($key, '.'))
        {
            $keyParts = explode('.', $key);
            $key = $keyParts[0];
            unset($keyParts[0]);
            $keyExtra = implode('.', $keyParts);
        }

        $value = $data[$key] ?? null;
        if (!empty($value))
        {
            if (!empty($keyExtra))
            {
                if (is_array($value)) {
                    return $this->getConfigValue($keyExtra, $value);
                }
                $value = null;
            }
            elseif (is_string($value) && str_contains($value, '|')) {
                $value = explode('|', $value);
            }
        }
        return empty($value) && $value !== false ? null : $value;
    }


    public function isEnabled(): bool
    {
        return $this->getConfigValue('enabled') !== false;
    }

    public function getFieldName(): string
    {
        return $this->getName();
    }

    public function getDoInheritFieldName(): string
    {
        return $this->getConfigValue('values.{inherit}');
    }

    public function getSourceFieldName(): string
    {
        return $this->getConfigValue('values.{source}');
    }


    public function getSource(): ?string
    {
        $source = $this->getForceSource();
        if (is_null($source))
        {
            if ($this->isInherited()) {
                $source = $this->getInheritSource();
            }
            else {
                $source = $this->getSelectedSource();
                if (is_null($source) || !$this->isSourceAvailable($source)) {
                    $source = $this->getDefaultSource();
                }
            }
        }
        return $source;
    }

    public function getValue()
    {
        $value = null;
        if ($this->isEnabled()) {
            $source = $this->getSource();
            $value = $this->getSourceValue($source);
        }
        return $value;
    }


    public function isInheritable(): bool
    {
        $dObj = $this->getDataObject();
        $inheritFieldName = $this->getDoInheritFieldName();
        $inheritSource = $this->getInheritSource();
        return !empty($inheritSource)
            && $this->isSourceAvailable($inheritSource)
            && $dObj->hasField($inheritFieldName);
    }

    public function isInherited(): bool
    {
        $dObj = $this->getDataObject();
        $inheritFieldName = $this->getDoInheritFieldName();
        return $this->isInheritable()
            && $dObj->getField($inheritFieldName);
    }

    public function getInheritSource(): ?string
    {
        $source = $this->getConfigValue('sources.inherit');
        if (is_array($source)) {
            $source = reset($source);
        }
        if (empty($source)) {
            $source = null;
        }
        return $source;
    }


    public function getDefaultSource(): ?string
    {
        $source = $this->getConfigValue('sources.default');
        if (is_array($source)) {
            $source = reset($source);
        }
        if ($source === false) {
            $source = null;
        }
        return $source;
    }

    public function getForceSource(): ?string
    {
        $source = $this->getConfigValue('sources.force');
        if (empty($source)) {
            $source = null;
        }
        elseif (is_array($source)) {
            $force = reset($source);
            foreach ($source as $sourcePart) {
                if ($this->isSourceAvailable($sourcePart)) {
                    $force = $sourcePart;
                    break;
                }
            }
            $source = $force;
        }
        return $source;
    }

    public function getSelectSources(): ?array
    {
        $sources = $this->getConfigValue('sources.select');
        if ($sources === false) {
            $sources = null;
        }
        if (is_string($sources)) {
            $sources = [$sources];
        }
        return $sources;
    }

    public function getSelectedSource(): ?string
    {
        $fieldName = $this->getSourceFieldName();
        $source = $this->getDataObject()->getField($fieldName);
        return empty($source) || $source === self::SOURCE_DEFAULT
            ? null
            : $source;
    }


    public function getSourceValue(?string $source)
    {
        if ($source === self::SOURCE_DEFAULT) {
            $source = $this->getDefaultSource();
        }

        if (empty($source)) {
            return null;
        }

        if ($source === self::SOURCE_NONE) {
            return null;
        }

        $dObj = $this->getDataObject();

        $methodName = $this->getMethodNameForSource($source);
        if ($methodName === false) {
            return null;
        }
        if (!is_null($methodName)) {
            return $dObj->{$methodName}();
        }

        $fieldName = $this->getFieldNameForSource($source);
        if ($fieldName === false) {
            return null;
        }
        if (!is_null($fieldName)) {
            return $dObj->getField($fieldName);
        }

        $relation = $this->getSourceRelation($source);
        if (!is_null($relation)) {
            $resourceful = static::create();
            $resourceful->setDataObject($relation);
            $resourceful->setName($this->getName());
            $value = $resourceful->getValue();
            unset($resourceful);
            return $value;
        }

        return null;
    }

    public function getSourceRelation(?string $source): ?DataObject
    {
        if (empty($source)) {
            return null;
        }

        $dObj = $this->getDataObject();

        $methodName = $this->getRelationMethodNameForSource($source);
        if ($methodName === false) {
            return null;
        }
        if (!is_null($methodName)) {
            return $dObj->{$methodName}();
        }

        $relationName = $this->getRelationNameForSource($source);
        if ($relationName === false) {
            return null;
        }
        if (!is_null($relationName)) {
            $relation = $dObj->{$relationName}();
            return $relation && $relation->exists() ? $relation : null;
        }

        if ($source === self::SOURCE_SITE) {
            return $this->getFallbackSite();
        }

        return null;
    }

    public function getFallbackSite(): ?DataObject
    {
        $site = null;
        $manifest = ModuleLoader::inst()->getManifest();
        $multisitesExists = $manifest->moduleExists('symbiote/silverstripe-multisites')
            || $manifest->moduleExists('fromholdio/silverstripe-configured-multisites');
        if ($multisitesExists)
        {
            $dObj = $this->getDataObject();
            if (!is_null($dObj->getRelationType('Site'))) {
                $site = $dObj->Site();
            }
        }
        else {
            $site = SiteConfig::current_site_config();
        }
        return $site && $site->exists() && $site->hasExtension(ResourcefulDataExtension::class)
            ? $site
            : null;
    }


    public function getMethodNameForSource(string $source)
    {
        $valueKeys = $this->getConfigValue('values');
        $methodNames = $valueKeys[$source] ?? null;
        if (!is_array($methodNames)) {
            $methodNames = [$methodNames];
        }
        foreach ($methodNames as $methodName) {
            if (is_string($methodName) && mb_strpos($methodName, '->') === 0)
            {
                $methodName = mb_substr($methodName, 2);
                if ($this->getDataObject()->hasMethod($methodName)) {
                    break;
                }
            }
            elseif ($methodName === false) {
                break;
            }
            $methodName = null;
        }
        return $methodName;
    }

    public function getFieldNameForSource(string $source)
    {
        $valueKeys = $this->getConfigValue('values');
        $fieldNames = $valueKeys[$source] ?? null;
        if (!is_array($fieldNames)) {
            $fieldNames = [$fieldNames];
        }
        foreach ($fieldNames as $fieldName) {
            if (is_string($fieldName) && mb_strpos($fieldName, '->') !== 0)
            {
                $dObj = $this->getDataObject();
                if ($dObj->hasField($fieldName) && is_null($dObj->getRelationType($fieldName))) {
                    break;
                }
            }
            elseif ($fieldName === false) {
                break;
            }
            $fieldName = null;
        }
        return $fieldName;
    }

    public function getRelationMethodNameForSource(string $source)
    {
        $relationKeys = $this->getConfigValue('relations');
        $methodNames = $relationKeys[$source] ?? null;
        if (!is_array($methodNames)) {
            $methodNames = [$methodNames];
        }
        foreach ($methodNames as $methodName) {
            if (is_string($methodName) && mb_strpos($methodName, '->') === 0)
            {
                $methodName = mb_substr($methodName, 2);
                if ($this->getDataObject()->hasMethod($methodName)) {
                    break;
                }
            }
            elseif ($methodName === false) {
                break;
            }
            $methodName = null;
        }
        return $methodName;
    }

    public function getRelationNameForSource(string $source)
    {
        $relationKeys = $this->getConfigValue('relations');
        $relationNames = $relationKeys[$source] ?? null;
        if (!is_array($relationNames)) {
            $relationNames = [$relationNames];
        }
        foreach ($relationNames as $relationName) {
            if (is_string($relationName) && mb_strpos($relationName, '->') !== 0)
            {
                if (!is_null($this->getDataObject()->getRelationType($relationName))) {
                    break;
                }
            }
            elseif ($relationName === false) {
                break;
            }
            $relationName = null;
        }
        return $relationName;
    }


    public function isSourceAvailable(?string $source): bool
    {
        if (empty($source)) {
            return false;
        }
        if ($source === self::SOURCE_NONE || $source === self::SOURCE_DEFAULT) {
            return true;
        }
        $fieldName = $this->getFieldNameForSource($source);
        if (!is_null($fieldName)) {
            return $fieldName !== false;
        }
        $methodName = $this->getMethodNameForSource($source);
        if (!is_null($methodName)) {
            return $methodName !== false;
        }
        if ($this->isSourceRelationRequired($source))
        {
            $relation = $this->getSourceRelation($source);
            return !is_null($relation);
        }
        $relationName = $this->getRelationNameForSource($source);
        if (!is_null($relationName)) {
            return $relationName !== false;
        }
        $relationMethodName = $this->getRelationMethodNameForSource($source);
        if (!is_null($relationMethodName)) {
            return $relationMethodName !== false;
        }
        if ($source === self::SOURCE_SITE) {
            return !is_null($this->getFallbackSite());
        }
        return false;
    }

    public function getAvailableSources(): ?array
    {
        $sources = $this->getSelectSources();
        if (!is_null($sources)) {
            foreach ($sources as $source) {
                if ($this->isSourceAvailable($source)) {
                    $available[] = $source;
                }
            }
        }
        return empty($available) ? null : $available;
    }

    public function isSourceRelationRequired(string $source): bool
    {
        $required = $this->getConfigValue('relations.{require}');
        if (empty($required)) {
            return false;
        }
        if (is_string($required)) {
            $required = [$required];
        }
        return in_array($source, $required);
    }


    public function getCMSFieldsConfig(): ?array
    {
        $config = null;
        $data = $this->getConfigData();
        if (!is_null($data) && isset($data['cms_fields'])) {
            $config = $data['cms_fields'];
        }
        return empty($config) ? null : $config;
    }

    public function getSettingsFieldsConfig(): ?array
    {
        $config = null;
        $data = $this->getConfigData();
        if (!is_null($data) && isset($data['settings_fields'])) {
            $config = $data['settings_fields'];
        }
        return empty($config) ? null : $config;
    }


    public function getCMSFields(): ?FieldList
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $fields = FieldList::create();

        $dObj = $this->getDataObject();
        $fieldName = $this->getFieldName();
        $method = 'get' . $fieldName . 'CMSFields';
        if ($dObj->hasMethod($method)) {
            return $dObj->{$method}();
        }

        $doInheritField = null;
        if ($this->isInheritable()) {
            $doInheritField = $this->getDoInheritCMSField();
            $fields->push($doInheritField);
        }

        $sourceField = $this->getSourceCMSField();
        if (!is_null($sourceField))
        {
            $sourceWrapper = Wrapper::create();
            $sourceWrapper->setName($sourceField->getName() . '_Wrapper');

            $sourceWrapper->push($sourceField);
            $fields->push($sourceWrapper);

            if (!is_null($doInheritField)) {
                $doInheritFieldName = $this->getDoInheritFieldName();
                $sourceWrapper->displayIf($doInheritFieldName)->isNotChecked();
                $sourceField->setTitle(' ');
            }

            $sourceFieldName = $this->getSourceFieldName();
            if (empty($dObj->getField($sourceFieldName))) {
                $dObj->setField($sourceFieldName, 'default');
            }

            $defaultSource = $this->getDefaultSource();
            if (is_a($sourceField, HiddenField::class, false))
            {
                $source = $sourceField->Value();
                if ($source === self::SOURCE_DEFAULT) {
                    $source = $defaultSource;
                }
                $sourceFieldList = $this->getCMSFieldsForSource($source);
                if (!is_null($sourceFieldList)) {
                    foreach ($sourceFieldList as $sourceFieldItem) {
                        $sourceWrapper->push($sourceFieldItem);
                    }
                }
            }
            else {
                $sources = $this->getAvailableSources();
                if (!is_null($sources)) {
                    foreach ($sources as $source)
                    {
                        $sourceFieldList = $this->getCMSFieldsForSource($source);
                        if (!is_null($sourceFieldList))
                        {
                            $sourceFieldsWrapper = Wrapper::create($sourceFieldList);
                            $sourceFieldsWrapper->setName($sourceFieldName . '_' . $source . '_Wrapper');

                            $displayIfEqualTo = $source === $defaultSource ? self::SOURCE_DEFAULT : $source;
                            $sourceFieldsWrapper->displayIf($sourceFieldName)->isEqualTo($displayIfEqualTo);

                            $sourceWrapper->push($sourceFieldsWrapper);
                        }
                    }
                }
            }
        }
        return $fields->count() > 0 ? $fields : null;
    }

    public function getDoInheritCMSField(): CheckboxFieldGroup
    {
        $dObj = $this->getDataObject();
        $doInheritFieldName = $this->getDoInheritFieldName();
        return CheckboxFieldGroup::create(
            $doInheritFieldName,
            $dObj->fieldLabel($doInheritFieldName),
            $dObj->fieldLabel($doInheritFieldName . 'Group')
        );
    }

    public function getSourceCMSField(): ?FormField
    {
        $field = null;
        $dObj = $this->getDataObject();
        $name = $this->getSourceFieldName();
        $class = $this->getSourceCMSFieldClass();
        $options = $this->getSourceCMSFieldOptions();
        if (!is_null($class) && !is_null($options))
        {
            if (count($options) === 1) {
                $field = HiddenField::create(
                    $name,
                    false,
                    array_key_first($options)
                );
            }
            else {
                /** @var SingleSelectField $class */
                $field = $class::create(
                    $name,
                    $dObj->fieldLabel($name),
                    $options
                );
            }
        }
        return $field;
    }

    public function getCMSFieldsForSource(string $source): ?FieldList
    {
        $dObj = $this->getDataObject();
        $fieldName = $this->getFieldName();
        $isDefault = $source === $this->getDefaultSource();
        $method = 'getCMSFields' . '_' . $fieldName . '_' . $source;

        if (!$dObj->hasMethod($method)) {
            return null;
        }

        $fields = $dObj->{$method}(
            $this->isInherited(),
            $this->getSelectedSource(),
            $isDefault,
            $this->getFieldNameForSource($source),
            $this->getRelationNameForSource($source)
        );

        if (is_a($fields, FormField::class, false)) {
            $fields = FieldList::create($fields);
        }
        return is_a($fields, FieldList::class, false) && $fields->count() > 0
            ? $fields
            : null;
    }

    public function getSourceCMSFieldClass(): ?string
    {
        $class = OptionsetField::class;
        $config = $this->getCMSFieldsConfig();
        if (!is_null($config))
        {
            $fieldClass = $this->getConfigValue('source_field_class');
            if (empty($fieldClass)) {
                $class = OptionsetField::class;
            }
            elseif (is_a($fieldClass, SingleSelectField::class, true)) {
                $class = $fieldClass;
            }
        }
        return $class;
    }

    public function getSourceCMSFieldOptions(): ?array
    {
        $sources = $this->getAvailableSources();
        if (empty($sources)) {
            return null;
        }
        $dObj = $this->getDataObject();
        $defaultSource = $this->getDefaultSource();
        $options = [];
        foreach ($sources as $source)
        {
            $optionKey = !empty($defaultSource) && $defaultSource === $source
                ? self::SOURCE_DEFAULT
                : $source;
            $options[$optionKey] = $dObj->fieldLabel(
                $this->getSourceFieldName() . '_' . $source
            );
        }
        return empty($options) ? null : $options;
    }


    public static function placeAllCMSFields(FieldList $fields, DataObject $dObj): FieldList
    {
        $names = static::getAllNames($dObj);
        if (is_null($names)) {
            return $fields;
        }
        $self = static::inst($dObj, $names[0]);
        foreach ($names as $name) {
            $self->setName($name);
            $fields = $self->removeCMSFields($fields);
        }
        foreach ($names as $name) {
            $self->setName($name);
            $fields = $self->placeCMSFields($fields);
        }
        return $fields;
    }

    public static function placeAllSettingsFields(FieldList $fields, DataObject $dObj): FieldList
    {
        $names = static::getAllNames($dObj);
        if (is_null($names)) {
            return $fields;
        }
        $self = static::inst($dObj, $names[0]);
        foreach ($names as $name) {
            $self->setName($name);
            $fields = $self->removeCMSFields($fields);
        }
        foreach ($names as $name) {
            $self->setName($name);
            $fields = $self->placeSettingsFields($fields);
        }
        return $fields;
    }

    public function placeCMSFields(FieldList $fields, bool $doRemoveFields = false): FieldList
    {
        if ($doRemoveFields) {
            $fields = $this->removeCMSFields($fields);
        }
        $config = $this->getCMSFieldsConfig();
        $cmsFields = $this->getCMSFields();
        if (!is_null($config) && !is_null($cmsFields))
        {
            $dObj = $this->getDataObject();
            $fields = CMSFieldsPlacement::placeFields($fields, $cmsFields, $config, $dObj);
        }
        return $fields;
    }

    public function placeSettingsFields(FieldList $fields, bool $doRemoveFields = false): FieldList
    {
        if ($doRemoveFields) {
            $fields = $this->removeCMSFields($fields);
        }
        $config = $this->getSettingsFieldsConfig();
        $cmsFields = $this->getCMSFields();
        if (!is_null($config) && !is_null($cmsFields))
        {
            $dObj = $this->getDataObject();
            $fields = CMSFieldsPlacement::placeFields($fields, $cmsFields, $config, $dObj);
        }
        return $fields;
    }

    public function removeCMSFields(FieldList $fields): FieldList
    {
        return $fields->removeByName([
            $this->getDoInheritFieldName(),
            $this->getSourceFieldName(),
//            $this->getLocalFieldName()
        ]);
    }
}
