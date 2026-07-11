--TEST--
AOT ngettext() default-domain plural helper (#14976)
--FILE--
<?php
echo (int) function_exists('ngettext'), "\n";
echo ngettext('item', 'items', 1), "\n";
echo ngettext('item', 'items', 2), "\n";
--EXPECT--
1
item
items
