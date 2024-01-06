# silverstripe-resourceful

Often a CMS UI will have a recurring pattern of a checkbox to enable/disable inheritance of a value/s, and when unchecked, the user can either choose an alternative source of the value, or enter fresh values into a form field/s.

For example:
- by default I inherit the feature image for a page from its parent page
- if I disable/uncheck that inheritance, I'm shown an optionsetfield to choose whether to a) use the global default feature image, or b) upload a new feature image
- when I choose b), I'm shown a file upload field to upload a new feature image

This module is quite abstract, it provides the means to implement this pattern, mainly through config yml.

Requires Silverstripe 4+ or 5+.
