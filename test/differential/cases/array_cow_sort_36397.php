<?php
// @differential-repeat: 10 COW sort must separate before mutate (#36397)
/**
 * By-value `$b = $a` shares the hashtable; sort($a) must not mutate $b
 * (php-src php_array_sort → SEPARATE_ARRAY).
 */
$a = [3, 1, 2];
$b = $a;
sort($a);
echo implode(',', $a), '|', implode(',', $b), "\n";
