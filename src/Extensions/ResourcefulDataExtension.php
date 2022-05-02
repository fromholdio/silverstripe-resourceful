<?php

namespace Fromholdio\Resourceful\Extensions;

use Fromholdio\Resourceful\Resourceful;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;

class ResourcefulDataExtension extends DataExtension
{
    public function getResourceful(string $name): Resourceful
    {
        return Resourceful::inst($this->getOwner(), $name);
    }

    public function isResourcefulEnabled(string $name): bool
    {
        return $this->getResourceful($name)->isEnabled();
    }

    public function getResourcefulValue(string $name)
    {
        return $this->getResourceful($name)->getValue();
    }

    public function isResourcefulInheritable(string $name): bool
    {
        return $this->getResourceful($name)->isInheritable();
    }

    public function isResourcefulInherited(string $name): bool
    {
        return $this->getResourceful($name)->isInherited();
    }

    public function getResourcefulSourceFieldOptions(string $name): ?array
    {
        return $this->getResourceful($name)->getSourceFieldOptions();
    }

    public function populateDefaults(): void
    {
        Resourceful::setAllFieldDefaults($this->getOwner());
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $fields = Resourceful::placeAllCMSFields($fields, $this->getOwner());
    }

    public function updateSettingsFields(FieldList $fields): void
    {
        $fields = Resourceful::placeAllSettingsFields($fields, $this->getOwner());
    }

    public function getOwner(): DataObject
    {
        /** @var DataObject $owner */
        $owner = parent::getOwner();
        return $owner;
    }
}
