--TEST--
language: post-clone direct assign to readonly property rejected (issue #4245)
--FILE--
<?php
class Point {
    public function __construct(public readonly int $x) {}
}
$p = new Point(1);
$c = clone $p;
$c->x = 2;
--EXPECT_EXIT--
255
