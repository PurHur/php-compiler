--TEST--
language: Closure::call() rebinds ReflectionMethod::getClosure / fromCallable (#21927, zend_closures.c)
--FILE--
<?php
class T {
    private $x = 3;
    public function f() { return $this->x; }
}
$obj = new T;
$c = (new ReflectionMethod(T::class, 'f'))->getClosure($obj);
echo 'call=', $c->call(new T), "\n";
$c2 = Closure::fromCallable([new T, 'f']);
echo 'fromCallable_call=', $c2->call(new T), "\n";
// Arrow path must stay green (#6411 / #13531).
$fn = fn() => $this->x;
echo 'arrow_call=', $fn->call(new T), "\n";
--EXPECT--
call=3
fromCallable_call=3
arrow_call=3
