--TEST--
Language: non-readonly parent property redeclared readonly in child (#7359)
--FILE--
<?php
class B {
    public int $x = 1;
}
class C extends B {
    public readonly int $x;
}
echo "ok\n";
--EXPECT_EXIT--
255
