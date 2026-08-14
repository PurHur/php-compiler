<?php
/**
 * Repro #30906 — Fiber method excess argc → ArgumentCountError
 * (Zend/zend_fibers.c / zend_fibers.stub.php).
 */
function show(string $label, callable $fn): void
{
    try {
        $r = $fn();
        echo $label, ': ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
show('getCurrent', fn() => Fiber::getCurrent(1));
show('getCurrent_ok', fn() => Fiber::getCurrent());
$f = new Fiber(fn() => Fiber::suspend(1));
$f->start();
show('resume_extra', fn() => $f->resume(2, 'x'));
show('resume_ok', fn() => $f->resume(2));
$f2 = new Fiber(fn() => 1);
$f2->start();
show('getReturn', fn() => $f2->getReturn(1));
show('getReturn_ok', fn() => $f2->getReturn());
show('isRunning', fn() => $f2->isRunning(1));
show('isTerminated', fn() => $f2->isTerminated(1));
show('isSuspended', fn() => $f2->isSuspended(1));
show('isStarted', fn() => $f2->isStarted(1));
show('isTerminated_ok', fn() => $f2->isTerminated());
show('isStarted_ok', fn() => $f2->isStarted());
show('isRunning_ok', fn() => $f2->isRunning());
show('isSuspended_ok', fn() => $f2->isSuspended());
$f4 = new Fiber(fn() => Fiber::suspend());
$f4->start();
show('throw_extra', fn() => $f4->throw(new Exception('e'), 1));
show('throw_ok', fn() => $f4->throw(new Exception('e')));
