--TEST--
Fiber::resume() returns each suspend value in order (#18162, Zend/zend_fibers.c)
--FILE--
<?php
declare(strict_types=1);

$fiber = new Fiber(function (): void {
    Fiber::suspend('step1');
    Fiber::suspend('step2');
});
echo $fiber->start(), "\n";
echo $fiber->resume(), "\n";
echo $fiber->resume(), "\n";
--EXPECT--
step1
step2
