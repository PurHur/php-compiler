--TEST--
AOT: property_exists() on a class with a static property (#31966)
--FILE--
<?php
class C {
    public static $x = 1;
}
var_dump(property_exists(C::class, 'x'));
var_dump(property_exists('C', 'x'));
--EXPECT--
bool(true)
bool(true)
--EXPECT_EXIT--
0
