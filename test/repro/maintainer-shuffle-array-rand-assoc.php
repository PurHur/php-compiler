<?php

// Issue #4460 — shuffle()/array_rand() on associative arrays (php-src ext/standard/array.c).

$a = ['x' => 10, 'y' => 20, 'z' => 30];
shuffle($a);
var_dump(array_keys($a));
var_dump(count($a));
var_dump(array_values($a));

$b = ['k1' => 1, 'k2' => 2, 'k3' => 3];
$k = array_rand($b);
var_dump(is_string($k));
var_dump(isset($b[$k]));

$ks = array_rand($b, 2);
sort($ks);
var_dump($ks);
