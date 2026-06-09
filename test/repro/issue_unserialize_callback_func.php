<?php

declare(strict_types=1);

/**
 * Issue #6564 — unserialize_callback_func + missing class → __PHP_Incomplete_Class.
 *
 * php-src: ext/standard/var.c — php_var_unserialize class lookup + callback dispatch
 */

ini_set('unserialize_callback_func', 'class_exists');
$s = 'O:7:"Missing":0:{}';
$o = unserialize($s);
var_export($o);
echo "\n";
var_export(class_exists('Missing', false));
echo "\n";
