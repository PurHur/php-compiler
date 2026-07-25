--TEST--
Fiber::throw() caught then return — getReturn() holds value (Zend/zend_fibers.c, #23041)
--FILE--
<?php
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

$g = new Fiber(function () {
    Fiber::suspend('paused');
    return 'from-resume';
});
echo 'rstart=', var_export($g->start(), true), "\n";
echo 'resume=', var_export($g->resume(), true), "\n";
echo 'rreturn=', var_export($g->getReturn(), true), "\n";

$h = new Fiber(function () {
    try {
        Fiber::suspend('paused');
    } catch (Exception $e) {
        echo 'caught-again'."\n";
        return Fiber::suspend('again');
    }
});
echo 'hstart=', var_export($h->start(), true), "\n";
echo 'hthrow=', var_export($h->throw(new Exception('y')), true), "\n";
echo 'hsuspended=', $h->isSuspended() ? '1' : '0', "\n";
echo 'hresume=', var_export($h->resume(), true), "\n";
--EXPECT--
start='paused'
throw=NULL
term=1
return='caught:x'
rstart='paused'
resume=NULL
rreturn='from-resume'
hstart='paused'
hthrow=caught-again
'again'
hsuspended=1
hresume=NULL
