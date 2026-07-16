--TEST--
Fiber throw() catch+rethrow transfers to caller without continuing try (#19592, Zend/zend_fibers.c)
--FILE--
<?php
declare(strict_types=1);

$f = new Fiber(function () {
    Fiber::suspend('in');
    try {
        Fiber::suspend('inner');
    } catch (Throwable $e) {
        echo 'inner='.$e->getMessage()."\n";
        throw $e;
    }
});
echo 'start='.$f->start()."\n";
echo 'resume='.$f->resume()."\n";
try {
    $f->throw(new Exception('injected'));
    echo "after_throw\n";
} catch (Throwable $e) {
    echo 'outer='.$e->getMessage()."\n";
    echo 'terminated_in_catch='.($f->isTerminated() ? '1' : '0')."\n";
}
echo 'terminated='.($f->isTerminated() ? '1' : '0')."\n";
--EXPECT--
start=in
resume=inner
inner=injected
outer=injected
terminated_in_catch=1
terminated=1
