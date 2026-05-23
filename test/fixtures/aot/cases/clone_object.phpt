--TEST--
AOT: clone shallow-copies instance properties (issue #1223)
--FILE--
<?php
class C {
    public int $x = 1;
}
$a = new C;
$a->x = 2;
$b = clone $a;
$b->x = 9;
echo $a->x, "\n", $b->x, "\n";
--EXPECT--
2
9
--EXPECT_EXIT--
0
