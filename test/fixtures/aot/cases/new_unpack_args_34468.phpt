--TEST--
AOT: constructor call-time unpack must pass args (#34468)
--FILE--
<?php
class C {
    public int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$a = [3];
echo (new C(...$a))->x, "\n";
echo (new C(...[7]))->x, "\n";
class D {
    public int $a;
    public int $b;
    public function __construct(int $a, int $b = 9) { $this->a = $a; $this->b = $b; }
}
$d = new D(...[1, 2]);
echo $d->a, '-', $d->b, "\n";
$e = new D(...[4]);
echo $e->a, '-', $e->b, "\n";
function f(int $x) { echo $x, "\n"; }
f(...$a);
class M {
    public function m(int $x) { echo 'm:', $x, "\n"; }
}
(new M())->m(...$a);
--EXPECT--
3
7
1-2
4-9
3
m:3
