--TEST--
Language: clone with duplicate readonly property assign rejected (issue #7250)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$c = new C(1);
$d = clone $c with { x: 2, x: 3 };
--EXPECT_EXIT--
255
