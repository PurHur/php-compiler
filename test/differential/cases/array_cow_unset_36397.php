<?php
// @differential-repeat: 10 COW unset must separate before mutate (#36397 / #34508)
/**
 * By-value `$b = $a` shares the hashtable; unset($a[0]) must not mutate $b
 * (php-src ZEND_UNSET_DIM → SEPARATE_ARRAY).
 */
$a = [1, 2];
$b = $a;
unset($a[0]);
echo isset($a[0]) ? '1' : '0';
echo isset($b[0]) ? '1' : '0';
echo '|', count($a), '|', count($b), "\n";
