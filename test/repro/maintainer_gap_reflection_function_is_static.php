<?php
/** Repro #22024 — ReflectionFunction::isStatic() vs Zend. */
$f = function () {};
$s = static function () {};
var_export((new ReflectionFunction($f))->isStatic());
echo "\n";
var_export((new ReflectionFunction($s))->isStatic());
echo "\n";
function plain_rf_is_static_repro() {}
var_export((new ReflectionFunction('plain_rf_is_static_repro'))->isStatic());
echo "\n";
var_export((new ReflectionFunction('strlen'))->isStatic());
echo "\n";
