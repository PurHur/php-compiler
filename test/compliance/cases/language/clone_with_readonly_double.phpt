--TEST--
Language: clone with readonly property double assign in with block (#7250)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$c = new C(1);
try {
    $d = clone $c with { x: 2, x: 3 };
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly property C::$x
