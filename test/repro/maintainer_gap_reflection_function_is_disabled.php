<?php
/** Repro #22165 — ReflectionFunction::isDisabled() vs Zend (ext/reflection/php_reflection.c). */
$rf = new ReflectionFunction('strlen');
var_export(method_exists($rf, 'isDisabled'));
echo "\n";
var_export($rf->isDisabled());
echo "\n";
$closure = function (): void {};
$rc = new ReflectionFunction($closure);
var_export(method_exists($rc, 'isDisabled'));
echo "\n";
var_export($rc->isDisabled());
echo "\n";
function plain_rf_is_disabled_repro(): void {}
$ru = new ReflectionFunction('plain_rf_is_disabled_repro');
var_export(method_exists($ru, 'isDisabled'));
echo "\n";
var_export($ru->isDisabled());
echo "\n";
