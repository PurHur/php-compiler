--TEST--
language: clone copies uninitialized readonly property; write on clone rejected (issue #4245)
--FILE--
<?php
class Box {
    public readonly int $x;
    public function __construct() {}
}
$b = new Box();
$c = clone $b;
$c->x = 1;
--EXPECT_EXIT--
255
