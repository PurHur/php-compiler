--TEST--
AOT: readonly class property write rejected after construction (#4082)
--FILE--
<?php
readonly class Box {
    public function __construct(public int $v) {}
}
$b = new Box(1);
$b->v = 2;
--EXPECT--
--EXPECT_EXIT--
255
