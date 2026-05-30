--TEST--
language: clone readonly class rejects property write on clone (issue #3430)
--FILE--
<?php
readonly class Box {
    public function __construct(public string $v) {}
}
$a = new Box('a');
$b = clone $a;
$b->v = 'b';
--EXPECT_EXIT--
255
