<?php
// @differential-repeat: 10 COW array_multisort must separate before mutate (#36397)
/**
 * By-value `$b = $a` shares the hashtable; array_multisort($a, $c) must not mutate $b
 * (php-src php_array_multisort → SEPARATE_ARRAY).
 */
$a = [3, 1, 2];
$b = $a;
$c = ['c', 'a', 'b'];
array_multisort($a, $c);
echo implode(',', $a), '|', implode(',', $b), '|', implode(',', $c), "\n";
