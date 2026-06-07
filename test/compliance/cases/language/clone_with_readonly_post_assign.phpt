--TEST--
Language: clone with readonly property post-clone assign rejected (#7250, #7245 guard)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$c = new C(1);
$d = clone $c with { x: 2 };
try {
    $d->x = 3;
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot modify readonly property C::$x
