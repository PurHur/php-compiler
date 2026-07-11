--TEST--
language: arrow/closure parent:: via Closure::call() uses bound scope (#12963, zend_closures.c)
--FILE--
<?php
class Base {
    public function value(): int { return 1; }
}
class Child extends Base {
    public function value(): int { return 2; }
    public static function makeArrow(): Closure {
        return fn (): int => parent::value();
    }
    public static function makeClosure(): Closure {
        return function (): int { return parent::value(); };
    }
}
class GrandChild extends Child {}

$gc = new GrandChild();
echo Child::makeArrow()->call($gc), "\n";
echo Child::makeClosure()->call($gc), "\n";

$c = new Child();
$fn = fn (): int => parent::value();
echo $fn->call($c), "\n";
--EXPECT--
2
2
1
