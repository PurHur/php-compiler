<?php
// @differential-repeat: 10 COW array_push must separate before mutate (#36397 / #34508)
/**
 * By-value `$b = $a` shares the hashtable; array_push($a, 3) must not mutate $b
 * (php-src array_push → SEPARATE_ARRAY / zend_hash_next_index_insert).
 */
$a = [1, 2];
$b = $a;
array_push($a, 3);
echo count($a), '|', count($b), '|';
echo isset($b[2]) ? '1' : '0';
echo "\n";
