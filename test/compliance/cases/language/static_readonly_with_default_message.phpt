--TEST--
Language: static readonly with default — cannot have default value (#26487)
--FILE--
<?php
class A {
    public static readonly int $x = 1;
}
echo "ok\n";
--EXPECT_EXIT--
255
