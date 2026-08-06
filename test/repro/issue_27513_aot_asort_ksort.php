<?php
// Issue #27513 — AOT asort()/ksort() must compile and match Zend (thin standalone).
// php-src: ext/standard/array.c — PHP_FUNCTION(asort) / PHP_FUNCTION(ksort)
$a = ['b' => 2, 'a' => 1, 'c' => 3];
asort($a);
echo implode(',', array_keys($a)), '|', implode(',', array_values($a)), "\n";
$b = ['b' => 2, 'a' => 1, 'c' => 3];
ksort($b);
echo implode(',', array_keys($b)), '|', implode(',', array_values($b)), "\n";
