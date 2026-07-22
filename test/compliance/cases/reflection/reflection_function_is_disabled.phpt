--TEST--
ReflectionFunction::isDisabled() always false (php-src; #22165, ext/reflection/php_reflection.c)
--FILE--
<?php
$rf = new ReflectionFunction('strlen');
echo var_export(method_exists($rf, 'isDisabled'), true), "\n";
echo var_export($rf->isDisabled(), true), "\n";
$closure = function (): void {};
$rc = new ReflectionFunction($closure);
echo var_export(method_exists($rc, 'isDisabled'), true), "\n";
echo var_export($rc->isDisabled(), true), "\n";
function plain_rf_is_disabled(): void {}
$ru = new ReflectionFunction('plain_rf_is_disabled');
echo var_export(method_exists($ru, 'isDisabled'), true), "\n";
echo var_export($ru->isDisabled(), true), "\n";
?>
--EXPECT--
true
false
true
false
true
false
