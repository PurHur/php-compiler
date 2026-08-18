--TEST--
AOT: array literal stored to a static property (#31967)
--FILE--
<?php
class C {
    public static $a = [1];
}
echo C::$a[0];
--EXPECT--
1
