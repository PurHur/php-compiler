--TEST--
language: Closure::fromCallable / FCC static method preserves named-class LSB (#24431, zend_closures.c)
--FILE--
<?php
class ClosureFromCallableLsbA {
    public static function foo() {
        return static::class;
    }
}
class ClosureFromCallableLsbB extends ClosureFromCallableLsbA {}

$c = Closure::fromCallable([ClosureFromCallableLsbB::class, 'foo']);
echo $c(), "\n";
$c2 = Closure::fromCallable('ClosureFromCallableLsbB::foo');
echo $c2(), "\n";
$c3 = ClosureFromCallableLsbB::foo(...);
echo $c3(), "\n";
$c4 = Closure::fromCallable([ClosureFromCallableLsbA::class, 'foo']);
echo $c4(), "\n";
--EXPECT--
ClosureFromCallableLsbB
ClosureFromCallableLsbB
ClosureFromCallableLsbB
ClosureFromCallableLsbA
