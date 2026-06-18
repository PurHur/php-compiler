--TEST--
Fiber throw() uncaught in fiber propagates to caller catch (#9784, Zend/zend_fibers.c)
--FILE--
<?php
declare(strict_types=1);

$f = new Fiber(function (): void {
    Fiber::suspend();
});

$f->start();

try {
    $f->throw(new Exception('x'));
    echo "no catch\n";
} catch (Throwable $e) {
    echo 'caught '.get_class($e).': '.$e->getMessage()."\n";
}
--EXPECT--
caught Exception: x
