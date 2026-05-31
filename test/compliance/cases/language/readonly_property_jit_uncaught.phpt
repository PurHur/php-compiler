--TEST--
readonly property: JIT uncaught write after construction (issue #3149)
--FILE--
<?php
class C {
    public readonly int $x = 1;
}
$c = new C();
$c->x = 2;
--EXPECT_EXIT--
255
