--TEST--
AOT: Fiber::resume passes value and terminates (#26801, Zend/zend_fibers.c)
--FILE--
<?php
$f = new Fiber(function (): void {
    $v = Fiber::suspend('paused');
    echo 'resumed:', $v, "\n";
});
echo 'start:', $f->start(), "\n";
echo 'resume:', $f->resume('go'), "\n";
echo 'status:', ($f->isTerminated() ? 'done' : 'live'), "\n";
--EXPECT--
start:paused
resume:resumed:go

status:done
