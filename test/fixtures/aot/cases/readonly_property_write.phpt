--TEST--
AOT: readonly property write rejected after construction (#3149)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
$c->x = 2;
--EXPECT--
--EXPECT_EXIT--
255
