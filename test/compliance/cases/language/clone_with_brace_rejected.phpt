--TEST--
Language: clone $obj with { } brace syntax rejected like Zend (#29187)
--FILE--
<?php
error_reporting(E_ALL);
class C {
    public function __construct(public int $x, public int $y = 0) {}
}
$o = new C(1, 2);
$n = clone $o with { x: 9 };
echo $n->x, '|', $n->y, "\n";
--EXPECT_EXIT--
255
