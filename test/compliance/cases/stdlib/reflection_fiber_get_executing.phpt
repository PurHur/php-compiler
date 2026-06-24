--TEST--
ReflectionFiber::getExecutingFiber() — active fiber introspection (#6793)
--FILE--
<?php
declare(strict_types=1);

echo 'class=', (int) class_exists('ReflectionFiber'), "\n";

var_export(ReflectionFiber::getExecutingFiber());
echo "\n";

$fiber = new Fiber(function (): void {
    $rf = ReflectionFiber::getExecutingFiber();
    var_export($rf instanceof ReflectionFiber);
    echo "\n";
    Fiber::suspend('step');
});

var_export($fiber->isStarted());
echo "\n";
$fiber->start();
var_export($fiber->isSuspended());
echo "\n";
--EXPECT--
class=1
NULL
false
true
true
