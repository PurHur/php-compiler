--TEST--
language: Fiber method excess argc → ArgumentCountError (#30906, Zend/zend_fibers.c)
--FILE--
<?php
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
--EXPECT--
getCurrent: ArgumentCountError: Fiber::getCurrent() expects exactly 0 arguments, 1 given
getCurrent_ok: NULL
resume_extra: ArgumentCountError: Fiber::resume() expects at most 1 argument, 2 given
resume_ok: NULL
getReturn: ArgumentCountError: Fiber::getReturn() expects exactly 0 arguments, 1 given
getReturn_ok: 1
isRunning: ArgumentCountError: Fiber::isRunning() expects exactly 0 arguments, 1 given
isTerminated: ArgumentCountError: Fiber::isTerminated() expects exactly 0 arguments, 1 given
isSuspended: ArgumentCountError: Fiber::isSuspended() expects exactly 0 arguments, 1 given
isStarted: ArgumentCountError: Fiber::isStarted() expects exactly 0 arguments, 1 given
isTerminated_ok: true
isStarted_ok: true
isRunning_ok: false
isSuspended_ok: false
throw_extra: ArgumentCountError: Fiber::throw() expects exactly 1 argument, 2 given
throw_ok: Exception: e
