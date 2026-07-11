--TEST--
ReflectionFiber instance API — construct, state, getFiber (#4609, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

echo 'class=', (int) class_exists('ReflectionFiber'), "\n";

$fiber = new Fiber(function (): void {
    Fiber::suspend('step');
});

$rf = new ReflectionFiber($fiber);
var_export($rf->isStarted());
echo "\n";
$fiber->start();
var_export($rf->isSuspended());
echo "\n";
var_export($rf->getFiber() === $fiber);
echo "\n";
--EXPECT--
class=1
false
true
true
