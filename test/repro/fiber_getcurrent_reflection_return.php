<?php
/**
 * Issue #27740: Fiber::getCurrent() Reflection return must be ?Fiber (Zend/zend_fibers.stub.php).
 */
$r = new ReflectionMethod(Fiber::class, 'getCurrent');
echo 'arity=', $r->getNumberOfParameters(), ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', PHP_EOL;
var_dump(Fiber::getCurrent());
$f = new Fiber(function () {
    echo Fiber::getCurrent() instanceof Fiber ? "infiber\n" : "bad\n";
    Fiber::suspend();
});
$f->start();
$f->resume();
