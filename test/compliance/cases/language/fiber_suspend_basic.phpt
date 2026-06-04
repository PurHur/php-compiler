--TEST--
Fiber::suspend() static call inside fiber callback (issue #5485, zend_fibers.c)
--FILE--
<?php
$f = new Fiber(function (): void {
    echo "start\n";
    Fiber::suspend("resume");
});
$f->start();
$f->resume();
--EXPECT--
start
