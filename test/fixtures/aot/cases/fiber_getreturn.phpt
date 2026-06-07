--TEST--
AOT: Fiber getReturn() after termination (issue #6310, Zend/zend_fibers.c)
--FILE--
<?php
$f = new Fiber(function (): int {
    return 42;
});
$f->start();
var_dump($f->getReturn());
--EXPECT--
int(42)
