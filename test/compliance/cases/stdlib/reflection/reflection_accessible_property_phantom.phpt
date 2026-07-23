--TEST--
ReflectionMethod/Property/Function $accessible is not PHP-visible (#22514, ext/reflection/php_reflection.c)
--FILE--
<?php
class T { function f(): void {} private int $p = 1; }
$rm = new ReflectionMethod(T::class, 'f');
echo 'RM property_exists=', var_export(property_exists($rm, 'accessible'), true), "\n";
echo 'RM isset=', var_export(isset($rm->accessible), true), "\n";
$rm->setAccessible(true);
echo 'RM invoke=', var_export($rm->invoke(new T()), true), "\n";
$rp = new ReflectionProperty(T::class, 'p');
echo 'RP property_exists=', var_export(property_exists($rp, 'accessible'), true), "\n";
$rp->setAccessible(true);
echo 'RP getValue=', var_export($rp->getValue(new T()), true), "\n";
$rf = new ReflectionFunction('strlen');
echo 'RF property_exists=', var_export(property_exists($rf, 'accessible'), true), "\n";
--EXPECT--
RM property_exists=false
RM isset=false
RM invoke=NULL
RP property_exists=false
RP getValue=1
RF property_exists=false
