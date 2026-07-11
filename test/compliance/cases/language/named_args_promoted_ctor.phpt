--TEST--
Named arguments to promoted constructor properties (issue #9427, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public function __construct(public int $n = 0) {}
}
function f(C $c): void { var_dump($c->n); }
f(new C(n: 42));

class D {
    public function __construct(public int $a, public int $b = 0) {}
}
$d = new D(b: 2, a: 1);
echo $d->a . "\n" . $d->b . "\n";

class E {
    public function __construct(public int $a, public int $b = 0, public int $c = 0) {}
}
$e = new E(c: 3, a: 1);
echo $e->a . "\n" . $e->b . "\n" . $e->c . "\n";
--EXPECT--
int(42)
1
2
1
0
3
