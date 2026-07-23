<?php

declare(strict_types=1);

class C
{
    private function m(): string
    {
        return 'secret';
    }

    private int $p = 42;
}

$rm = new ReflectionMethod(C::class, 'm');
var_export(method_exists($rm, 'setAccessible'));
echo "\n";
var_export(method_exists($rm, 'isAccessible'));
echo "\n";
$rm->setAccessible(true);
var_export($rm->invoke(new C()));
echo "\n";

$rp = new ReflectionProperty(C::class, 'p');
var_export(method_exists($rp, 'setAccessible'));
echo "\n";
var_export(method_exists($rp, 'isAccessible'));
echo "\n";
$rp->setAccessible(true);
var_export($rp->getValue(new C()));
echo "\n";

$rf = new ReflectionFunction('strlen');
var_export(method_exists($rf, 'setAccessible'));
echo "\n";
var_export(method_exists($rf, 'isAccessible'));
echo "\n";
