--TEST--
Fiber::resume() returns each suspend value in order (issue #18162)
--FILE--
<?php
$f = new Fiber(function (): void {
    Fiber::suspend('step1');
    Fiber::suspend('step2');
});
echo $f->start() . "\n";
echo $f->resume() . "\n";
echo $f->resume() . "\n";
--EXPECT--
step1
step2

