--TEST--
AOT: dim fetch on assigned static array property (#33936)
--FILE--
<?php
class C {
    public static $a;
}
C::$a = ["x" => 1];
echo C::$a["x"];
--EXPECT--
1
