<?php
declare(strict_types=1);

// Issue #10076 — array_all()/array_any() array:/callback: named parameters (php-src basic_functions.stub.php).

$a = [1, 2, 3];
var_export(array_all(array: $a, callback: fn ($v) => $v > 0));
echo "\n";
var_export(array_any(array: $a, callback: fn ($v) => $v > 2));
echo "\n";
var_export(array_any(callback: fn ($v) => $v > 2, array: $a));
echo "\n";
// String callbacks + inline array (positional control for named-param binding only)
var_export(array_all(array: [1, 2, 3], callback: 'is_int'));
echo "\n";
