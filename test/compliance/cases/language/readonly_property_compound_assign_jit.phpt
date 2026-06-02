--TEST--
readonly property: JIT rejects += after construction (issue #3149)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
$c = new C();
try {
    $c->x += 1;
    echo "mutated\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly property C::$x
