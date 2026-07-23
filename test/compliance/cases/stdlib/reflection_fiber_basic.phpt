--TEST--
ReflectionFiber instance API — construct, getFiber; state on Fiber (#4609, #22422, ext/reflection/php_reflection.c)
--FILE--
<?php
$fiber = new Fiber(function (): void {
    Fiber::suspend('step');
});

$rf = new ReflectionFiber($fiber);
var_export($fiber->isStarted());
echo "\n";
$fiber->start();
var_export($fiber->isSuspended());
echo "\n";
var_export($rf->getFiber() === $fiber);
echo "\n";
--EXPECT--
false
true
true
