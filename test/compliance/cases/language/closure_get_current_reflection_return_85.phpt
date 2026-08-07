--TEST--
language Closure::getCurrent() Reflection return is Closure on PROFILE=8.5 (#28710, Zend/zend_closures.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

$r = new ReflectionMethod(Closure::class, 'getCurrent');
echo 'static=', $r->isStatic() ? '1' : '0', ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
echo 'method_exists=', method_exists(Closure::class, 'getCurrent') ? '1' : '0', "\n";
$seen = null;
$f = function () use (&$seen) {
    $seen = Closure::getCurrent();
    return 1;
};
$f();
echo 'is_closure=', $seen instanceof Closure ? '1' : '0', "\n";
echo 'identity=', ($seen === $f) ? '1' : '0', "\n";
?>
--EXPECT--
static=1 ret=Closure
method_exists=1
is_closure=1
identity=1
