--TEST--
ReflectionMethod::getClosure object optional + at-most-one arg (#24433, php_reflection.stub.php)
--FILE--
<?php
$rf = new ReflectionMethod(ReflectionMethod::class, 'getClosure');
$p = $rf->getParameters()[0];
echo (int)$p->isOptional(), ' ', $rf->getNumberOfRequiredParameters(), "\n";

class A { public static function foo(){ return static::class; } }
class B extends A {}
$rm = new ReflectionMethod(A::class, 'foo');
$c0 = $rm->getClosure();
echo $c0(), "\n";
$c1 = $rm->getClosure(null);
echo $c1(), "\n";
try {
    $rm->getClosure(null, B::class);
    echo "2arg ok\n";
} catch (Throwable $e) {
    echo get_class($e), ' | ', $e->getMessage(), "\n";
}
--EXPECT--
1 0
A
A
ArgumentCountError | ReflectionMethod::getClosure() expects at most 1 argument, 2 given
