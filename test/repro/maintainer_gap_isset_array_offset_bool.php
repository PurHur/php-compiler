<?php

declare(strict_types=1);

/**
 * Issue #11498 — isset() on array offset must return bool, not NULL.
 * php-src: Zend/zend_operators.c (zend_isset_dim)
 */

$a = ['k' => 1];
$isset_key = isset($a['k']);
$isset_missing = isset($a['missing']);

$b = ['nested' => ['x' => 1]];
$isset_nested = isset($b['nested']['x']);
$isset_nested_missing = isset($b['nested']['missing']);

echo 'isset_key=' . var_export($isset_key, true) . PHP_EOL;
echo 'isset_missing=' . var_export($isset_missing, true) . PHP_EOL;
echo 'isset_nested=' . var_export($isset_nested, true) . PHP_EOL;
echo 'isset_nested_missing=' . var_export($isset_nested_missing, true) . PHP_EOL;

echo 'var_export_isset=' . var_export(isset($a['k']), true) . PHP_EOL;
echo 'var_export_missing=' . var_export(isset($a['missing']), true) . PHP_EOL;

$empty_key = empty($a['k']);
$empty_missing = empty($a['missing']);
echo 'empty_key=' . var_export($empty_key, true) . PHP_EOL;
echo 'empty_missing=' . var_export($empty_missing, true) . PHP_EOL;
echo 'var_export_empty=' . var_export(empty($a['k']), true) . PHP_EOL;
