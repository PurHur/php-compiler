--TEST--
stdlib inet_pton(null) JIT — null coerces to false on default profile (#19053, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
var_export(inet_pton(null));
echo "\n";
?>
--EXPECT--
false
