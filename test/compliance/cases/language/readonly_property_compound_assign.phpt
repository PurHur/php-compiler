--TEST--
readonly property: += rejected after construction (issue #3149)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
$c->x += 1;
--EXPECT_EXIT--
255
