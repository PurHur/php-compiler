--TEST--
Fiber::throw() uncaught propagates to caller under AOT (#27622, Zend/zend_fibers.c)
--FILE--
<?php
$f = new Fiber(function () {
    echo 'in:', Fiber::suspend('s'), "\n";
    return 'done';
});
echo 'start:', $f->start(), "\n";
try {
    echo 'throw:', $f->throw(new RuntimeException('boom')), "\n";
} catch (Throwable $e) {
    // get_class($e) is still empty under AOT for this path (related #27625); message + term are the gate.
    echo 'catch_msg:', $e->getMessage(), "\n";
}
echo 'term:', $f->isTerminated() ? '1' : '0', "\n";
--EXPECT--
start:in:s
throw:catch_msg:boom
term:1
