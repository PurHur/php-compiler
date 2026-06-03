<?php

/** Issue #5295 — array < > <= >= must use zend_compare_arrays (Zend/zend_operators.c). */

$a = [1];
$b = [2];

var_dump($a < $b);
var_dump($a > $b);
var_dump($a <= [1]);
var_dump($a >= $b);
