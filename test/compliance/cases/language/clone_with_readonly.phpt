--TEST--
Language: clone with readonly property reinit (PHP 8.3+, #7250)
--FILE--
<?php
class C {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$c = new C(1);
$d = clone $c with { x: 2 };
echo $d->x, "\n";
--EXPECT--
2
