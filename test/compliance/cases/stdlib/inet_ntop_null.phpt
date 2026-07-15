--TEST--
stdlib inet_ntop(null) — null coerces to false on default profile (#19053, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(inet_ntop(null));
echo "\n";
?>
--EXPECT--
false
