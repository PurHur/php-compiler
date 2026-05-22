--TEST--
include_scope: nested include chain inherits outer caller scope (#477)
--RUNFILE--
include_scope_inherit/nested_entry.php
--EXPECT--
nested-scope

