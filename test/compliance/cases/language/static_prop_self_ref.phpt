--TEST--
Static property assign-by-reference to same slot stores NULL (issue #5405)
--FILE--
<?php
class C {
    public static $x;
}
C::$x = &C::$x;
var_dump(C::$x);
--EXPECT--
NULL
