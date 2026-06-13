<?php
/**
 * Maintainer repro for #4789 — array_pop/shift/unshift on associative arrays.
 */
$a = ['x' => 1, 'y' => 2];
var_export(array_pop($a));
echo "\n";
var_export($a);
echo "\n";
$b = ['x' => 1, 'y' => 2];
var_export(array_shift($b));
echo "\n";
var_export($b);
echo "\n";
$c = ['x' => 1];
var_export(array_unshift($c, 'z'));
echo "\n";
var_export($c);
echo "\n";
