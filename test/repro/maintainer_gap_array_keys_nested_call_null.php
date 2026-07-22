<?php

/**
 * Issue #21981 — array_keys(nested array-returning builtin) must see the producer
 * HashTable (not null / not the producer's inline INIT_ARRAY).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_keys) / producers
 */
$x = array_flip(['a', 'b']);
var_export(array_keys($x));
echo "\n";
var_export(array_keys(array_flip(['a', 'b'])));
echo "\n";
var_export(array_keys(array_values(['x' => 1, 'y' => 2])));
echo "\n";
var_export(array_keys(array_slice(['a' => 1, 'b' => 2, 'c' => 3], 1, 1, true)));
echo "\n";
var_export(array_keys(array_combine(['k'], [1])));
echo "\n";
var_export(array_keys(array_fill_keys(['a', 'b'], 0)));
echo "\n";
var_export(array_keys(array_merge(['a' => 1], ['b' => 2])));
echo "\n";
var_export(array_keys(array_replace(['a' => 1], ['b' => 2])));
echo "\n";
var_export(array_keys(array_change_key_case(['A' => 1, 'B' => 2])));
echo "\n";
var_export(array_keys(array_unique(['a', 'a', 'b'])));
echo "\n";
var_export(array_keys(array_count_values(['a', 'a', 'b'])));
echo "\n";
