--TEST--
Fiber::getReturn() Reflection return is mixed (issue #27746, Zend/zend_fibers.stub.php)
--FILE--
<?php
$f = new Fiber(function () { return 42; });
$f->start();
echo 'ret=', $f->getReturn(), "\n";
$r = new ReflectionMethod(Fiber::class, 'getReturn');
echo 'ref=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
--EXPECT--
ret=42
ref=mixed
