--TEST--
stdlib ngettext() — default-domain plural helper (ext/gettext/gettext.c, #14976)
--FILE--
<?php
echo (int) function_exists('ngettext'), "\n";
echo ngettext('item', 'items', 1), "\n";
echo ngettext('item', 'items', 2), "\n";
--EXPECT--
1
item
items
