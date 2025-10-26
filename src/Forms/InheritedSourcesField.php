<?php

namespace Fromholdio\Resourceful\Forms;

use SilverStripe\Forms\CompositeField;
use SilverStripe\View\Requirements;

class InheritedSourcesField extends CompositeField
{
    public function __construct($children = null)
    {
        parent::__construct($children);
        $css = <<<CSS
.field.inheritedsources .field[id$="_DoInheritGroup_Holder"],
.field.inheritedsources .field[id$="_DoInheritGroup_Holder"] .field[id$="_DoInherit_Holder"] {
  margin-bottom: 0 !important;
}
.field.inheritedsources .field[id$="_DoInheritGroup_Holder"] .field[id$="_Source_Holder"]{
  margin-top: 0 !important;
}
CSS;
        Requirements::customCSS($css, self::class);
    }
}
