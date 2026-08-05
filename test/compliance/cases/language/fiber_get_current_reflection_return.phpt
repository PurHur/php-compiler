--TEST--
Fiber::getCurrent() Reflection return is ?Fiber (issue #27740, Zend/zend_fibers.stub.php)
--FILE--
<?php
$r = new ReflectionMethod(Fiber::class, 'getCurrent');
echo 'arity=', $r->getNumberOfParameters(), ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
var_dump(Fiber::getCurrent());
$f = new Fiber(function () {
    echo Fiber::getCurrent() instanceof Fiber ? "infiber\n" : "bad\n";
    Fiber::suspend();
});
$f->start();
$f->resume();
--EXPECT--
arity=0 ret=?Fiber
NULL
infiber
