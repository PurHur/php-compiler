--TEST--
Language: float bitwise ops — deprecate only on precision loss, not exact integrals (#23755)
--FILE--
<?php
error_reporting(E_ALL);
$w = [];
set_error_handler(function ($n, $s) use (&$w) { $w[] = $s; return true; });

// Exact integral floats — Zend: no deprecation
var_export(5.0 & 3); echo "\n";
var_export(5.0 | 3); echo "\n";
var_export(5.0 ^ 3); echo "\n";
echo "exact warns: " . count($w) . "\n";

// Lossy float — Zend: E_DEPRECATED
$w = [];
var_export(5.7 & 3); echo "\n";
echo "lossy warns: " . count($w) . "\n";

// Shift with exact float — no deprecation
$w = [];
var_export(8 << 1.0); echo "\n";
echo "shift exact warns: " . count($w) . "\n";

// Shift with lossy float — E_DEPRECATED
$w = [];
var_export(8 << 1.5); echo "\n";
echo "shift lossy warns: " . count($w) . "\n";
?>
--EXPECT--
1
7
6
exact warns: 0
1
lossy warns: 1
16
shift exact warns: 0
16
shift lossy warns: 1
