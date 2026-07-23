<?php
// #22514 — accessible is C-only on reflection_object (php-src).
class T
{
    function f(): void
    {
    }

    private int $p = 1;
}

$rm = new ReflectionMethod(T::class, 'f');
echo 'RM property_exists accessible=', var_export(property_exists($rm, 'accessible'), true), "\n";
echo 'RM isset accessible=', var_export(isset($rm->accessible), true), "\n";
$rm->setAccessible(true);
echo 'RM invoke still ok after setAccessible=', var_export($rm->invoke(new T()) === null, true), "\n";

$rp = new ReflectionProperty(T::class, 'p');
echo 'RP property_exists accessible=', var_export(property_exists($rp, 'accessible'), true), "\n";
$rp->setAccessible(true);
echo 'RP getValue=', var_export($rp->getValue(new T()), true), "\n";

$rf = new ReflectionFunction('strlen');
echo 'RF property_exists accessible=', var_export(property_exists($rf, 'accessible'), true), "\n";
