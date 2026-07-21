<?php
// Issue #21927 — Closure::call on ReflectionMethod::getClosure / fromCallable.
class T {
    private $x = 3;
    public function f() { return $this->x; }
}
$obj = new T;
$c = (new ReflectionMethod(T::class, 'f'))->getClosure($obj);
echo 'call=', $c->call(new T), "\n";
$c2 = Closure::fromCallable([new T, 'f']);
echo 'fromCallable_call=', $c2->call(new T), "\n";
