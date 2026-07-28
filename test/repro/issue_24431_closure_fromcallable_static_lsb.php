<?php
// Repro #24431 — Closure::fromCallable / FCC named-class late static binding.
class A {
    public static function foo()
    {
        return static::class;
    }
}
class B extends A {}

$c = Closure::fromCallable([B::class, 'foo']);
echo $c(), "\n";
$c2 = Closure::fromCallable('B::foo');
echo $c2(), "\n";
$c3 = B::foo(...);
echo $c3(), "\n";
