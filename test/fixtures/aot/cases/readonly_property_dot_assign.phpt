--TEST--
AOT: readonly property .= rejected after construction (#3149)
--FILE--
<?php
class C {
    public readonly string $x;
    public function __construct() {
        $this->x = 'a';
    }
}
$c = new C();
$c->x .= 'b';
--EXPECT--
--EXPECT_EXIT--
255
