<?php
/**
 * Issue #23041: Fiber::throw() caught inside fiber with return — getReturn() must match Zend.
 */
$f = new Fiber(function () {
    try {
        Fiber::suspend('paused');
    } catch (Exception $e) {
        return 'caught:'.$e->getMessage();
    }
    return 'normal';
});
echo 'start=', var_export($f->start(), true), "\n";
echo 'throw=', var_export($f->throw(new Exception('x')), true), "\n";
echo 'term=', $f->isTerminated() ? '1' : '0', "\n";
echo 'return=', var_export($f->getReturn(), true), "\n";
