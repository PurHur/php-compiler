<?php
// @differential-repeat: 10 COW shuffle must separate before mutate (#36397)
/**
 * By-value `$b = $a` shares the hashtable; shuffle($a) must not mutate $b
 * (php-src php_shuffle → SEPARATE_ARRAY). Sorted $a proves multiset preserved;
 * unsorted $b must stay the original order (deterministic vs Zend).
 */
$a = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
$b = $a;
shuffle($a);
$sorted = $a;
sort($sorted);
echo implode(',', $sorted), '|', implode(',', $b), "\n";
