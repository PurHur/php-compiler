<?php
// #29273 — unset(readonly) Error must say "readonly property", not protected(set).
class C {
    public function __construct(public readonly int $x) {}
}
$o = new C(1);
try {
    unset($o->x);
    echo "UNEXPECTED_OK\n";
} catch (Error $e) {
    echo "msg=" . $e->getMessage() . "\n";
    echo "exact=" . ($e->getMessage() === 'Cannot unset readonly property C::$x' ? "yes" : "no") . "\n";
}
