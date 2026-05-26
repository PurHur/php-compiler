--TEST--
unset() on a static property (#2256)
--FILE--
<?php
class C {
    public static $x = 1;
}
unset(C::$x);
C::$x = 2;
echo C::$x, "\n";
--EXPECT--
2
