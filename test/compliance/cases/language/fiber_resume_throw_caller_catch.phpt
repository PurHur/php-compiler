--TEST--
Fiber resume() throw transfers to caller catch without continuing try (#19592, Zend/zend_fibers.c)
--FILE--
<?php
declare(strict_types=1);

$f = new Fiber(function () {
    Fiber::suspend(1);
    throw new Exception('boom');
});
echo 'start='.$f->start()."\n";
try {
    $r = $f->resume();
    echo 'after_resume='.var_export($r, true)."\n";
} catch (Throwable $e) {
    echo 'catch='.$e->getMessage()."\n";
    echo 'terminated_in_catch='.($f->isTerminated() ? '1' : '0')."\n";
}
echo 'terminated='.($f->isTerminated() ? '1' : '0')."\n";
--EXPECT--
start=1
catch=boom
terminated_in_catch=1
terminated=1
