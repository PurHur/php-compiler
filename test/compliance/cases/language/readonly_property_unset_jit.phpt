--TEST--
readonly property: JIT unset() rejected after construction (issue #3149)
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
    unset($c->x);
    echo "unset\n";
} catch (\Throwable $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot unset readonly property C::$x
