--TEST--
Fiber throw() injects exception at suspension (Zend/zend_fibers.c parity, #4481)
--FILE--
<?php
$f = new Fiber(function (): void {
    try {
        Fiber::suspend("s1");
    } catch (Exception $e) {
        echo "caught: ".$e->getMessage()."\n";
    }
});

var_dump($f->start());
var_dump($f->throw(new Exception("boom")));
var_dump($f->isTerminated());
--EXPECT--
string(2) "s1"
caught: boom
NULL
bool(true)

