--TEST--
readonly property: inherited declaring class in error message (issue #3149)
--FILE--
<?php
class P {
    public readonly int $x;
    public function __construct() {
        $this->x = 1;
    }
}
class C extends P {}
$c = new C();
try {
    $c->x = 2;
    echo "mutated\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly property P::$x
