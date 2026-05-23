--TEST--
Language: instanceof for user-defined classes (#138)
--FILE--
<?php
class Box {}
class Other {}
$o = new Box();
var_export($o instanceof Box);
echo "\n";
var_export($o instanceof Other);
echo "\n";
var_export($o instanceof stdClass);
echo "\n";
var_export(null instanceof Box);
echo "\n";
var_export(0 instanceof Box);
echo "\n";
--EXPECT--
true
false
false
false
false
