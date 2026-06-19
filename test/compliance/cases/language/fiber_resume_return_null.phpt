--TEST--
Fiber::resume() returns NULL when fiber terminates (issue #10149, Zend/zend_fibers.c)
--FILE--
<?php
$f = new Fiber(function () {
    Fiber::suspend(1);
    return 99;
});
$f->start();
var_dump($f->resume());
var_dump($f->getReturn());

$g = new Fiber(function () {
    $v = Fiber::suspend();
    var_dump($v);
    return 1;
});
$g->start();
var_dump($g->resume(77));
var_dump($g->getReturn());
--EXPECT--
NULL
int(99)
int(77)
NULL
int(1)
