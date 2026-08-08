<?php
/**
 * #29088 — Zend: Decrement on non-numeric string has no effect and is deprecated
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/decrement_non_numeric_string.php
 */
error_reporting(E_ALL);
$s = 'A';
$s--;
echo "VAL=$s\n";
