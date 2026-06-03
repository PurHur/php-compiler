--TEST--
readonly property: ++/-- rejected after construction, promoted ctor (issue #4875)
--FILE--
<?php
class C {
    public function __construct(public readonly int $x = 0) {}
}
$c = new C(1);
try {
    $c->x++;
    echo "mutated\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    $c->x--;
    echo "mutated\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly property C::$x
Cannot modify readonly property C::$x
