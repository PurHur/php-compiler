--TEST--
Fiber throw() lifecycle errors throw FiberError (#4481, Zend/zend_fibers.c)
--FILE--
<?php
$f = new Fiber(function (): void {
    Fiber::suspend("x");
});

try {
    $f->throw(new Exception("boom"));
} catch (FiberError $e) {
    var_dump($e instanceof FiberError);
}

var_dump($f->start());
var_dump($f->resume());
var_dump($f->isTerminated());

try {
    $f->throw(new Exception("boom"));
} catch (FiberError $e) {
    var_dump($e instanceof FiberError);
}
--EXPECT--
bool(true)
string(1) "x"
NULL
bool(true)
bool(true)

