--TEST--
stdlib strstr() — null $needle coerces to "" without strict_types (#29766, ext/standard/string.c)
--FILE--
<?php
var_export(strstr('abc', null));
echo "\n";
--EXPECT--
'abc'
