--TEST--
readonly unset Error uses readonly wording, not protected(set) (#29273)
--FILE--
<?php
class C {
    public function __construct(public readonly int $x) {}
}
class D {
    public readonly int $y;
    public function __construct(int $y) {
        $this->y = $y;
    }
}
class E {
    public function __construct(public private(set) readonly int $z) {}
}
foreach (
    [
        'promoted' => [new C(1), 'x'],
        'declared' => [new D(1), 'y'],
        'priv_set_ro' => [new E(1), 'z'],
    ] as $label => [$o, $prop]
) {
    try {
        unset($o->$prop);
        echo "$label: UNEXPECTED_OK\n";
    } catch (Error $e) {
        echo "$label: ", $e->getMessage(), "\n";
    }
}
--EXPECT--
promoted: Cannot unset readonly property C::$x
declared: Cannot unset readonly property D::$y
priv_set_ro: Cannot unset readonly property E::$z
