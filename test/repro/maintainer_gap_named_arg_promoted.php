<?php
class C {
    public function __construct(public int $n = 0) {}
}
function f(C $c): void { var_dump($c->n); }
f(new C(n: 42));
