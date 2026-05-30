--TEST--
Fiber::getCurrent() inside and outside fiber (issue #3130)
--FILE--
<?php
echo Fiber::getCurrent() === null ? "outside\n" : "bad\n";
$fiber = new Fiber(function (): void {
    echo Fiber::getCurrent() !== null ? "inside\n" : "bad\n";
    Fiber::suspend(null);
});
$fiber->start();
$fiber->resume();
--EXPECT--
outside
inside
