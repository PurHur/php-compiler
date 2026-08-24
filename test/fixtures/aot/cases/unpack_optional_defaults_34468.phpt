--TEST--
AOT: call-time unpack missing optionals use defaults (#34468 follow-up)
--FILE--
<?php
class D {
    public int $a;
    public int $b;
    public function __construct(int $a, int $b = 9) { $this->a = $a; $this->b = $b; }
}
echo (new D(...[4]))->a, '-', (new D(...[4]))->b, "\n";
$x = 4;
$a = [$x];
$d = new D(...$a);
echo $d->a, '-', $d->b, "\n";
function g(int $a, int $b = 9) { echo $a, '-', $b, "\n"; }
$y = 7;
$g = [$y];
g(...$g);
--EXPECT--
4-9
4-9
7-9
