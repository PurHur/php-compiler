--TEST--
Fiber lifecycle errors throw FiberError (issue #4372)
--FILE--
<?php

$f = new Fiber(function (int $x): int {
    $y = $x + 1;
    $sent = Fiber::suspend($y);
    return $sent * 2;
});

var_dump($f->start(10));
var_dump($f->resume(21));
var_dump($f->isTerminated());

try {
    $f->resume(1);
} catch (FiberError $e) {
    var_dump($e instanceof FiberError);
}

try {
    Fiber::suspend("x");
} catch (FiberError $e) {
    var_dump($e instanceof FiberError);
}
--EXPECT--
int(11)
NULL
bool(true)
bool(true)
bool(true)

