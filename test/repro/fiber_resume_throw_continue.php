<?php
declare(strict_types=1);

/**
 * Issue #19592: Fiber::resume() throw must not continue the caller try body after catch
 * (Zend/zend_fibers.c). isTerminated() must be true immediately in the catch path.
 */
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
