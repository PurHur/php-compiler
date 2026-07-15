--TEST--
stdlib inet_pton(null) — null coerces to false on default profile (#19053, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(inet_pton(null));
echo "\n";
?>
--EXPECT--
false
