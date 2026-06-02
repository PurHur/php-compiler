--TEST--
AOT: readonly property unset() rejected after construction (#3149)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
unset($c->x);
--EXPECT--
--EXPECT_EXIT--
255
