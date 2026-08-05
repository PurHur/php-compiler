<?php
/**
 * Issue #27746: Fiber::getReturn() Reflection return must be mixed (Zend/zend_fibers.stub.php).
 */
$f = new Fiber(function () { return 42; });
$f->start();
echo 'ret=', $f->getReturn(), PHP_EOL;
$r = new ReflectionMethod(Fiber::class, 'getReturn');
echo 'ref=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', PHP_EOL;
