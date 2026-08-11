<?php
/**
 * Issue #30097 — (array)false must be [false], not [] (Zend convert_to_array).
 * php-src: Zend/zend_operators.c
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_30097_cast_false_to_array.php
 */
error_reporting(E_ALL);

var_export((array) false);
echo "\n";
var_export((array) true);
echo "\n";
var_export((array) null);
echo "\n";
