--TEST--
property_exists() on a boxed instance (#32688)
--FILE--
<?php
class C {
    public $x = 1;
    public static $s = 2;
}
$c = new C;
var_dump(property_exists($c, 'x'));
var_dump(property_exists($c, 's'));
var_dump(property_exists($c, 'missing'));
--EXPECT--
bool(true)
bool(true)
bool(false)
