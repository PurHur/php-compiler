--TEST--
AOT: readonly property ??= no-op when already set (#3149)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
$c->x ??= 2;
echo $c->x;
--EXPECT--
1
