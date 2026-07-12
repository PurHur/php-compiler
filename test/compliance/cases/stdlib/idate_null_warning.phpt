--TEST--
stdlib idate(null) — Warning + false not LogicException (#18372, ext/standard/datetime.c)
--FILE--
<?php
var_export(idate(null));
echo "\n";
?>
--EXPECTF--
PHP Warning:  idate(): idate format is one char in %s on line %d
false
