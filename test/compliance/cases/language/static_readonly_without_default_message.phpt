--TEST--
Language: static readonly without default — cannot be readonly (#26487)
--FILE--
<?php
class A {
    public static readonly int $x;
}
echo "ok\n";
--EXPECT_EXIT--
255
