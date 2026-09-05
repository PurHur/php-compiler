<?php
// @differential-repeat: 10 COW array_walk must separate before mutate (#36397)
/**
 * By-value `$b = $a` shares the hashtable; array_walk($a, …) by-ref
 * must not mutate $b (php-src php_array_walk → SEPARATE_ARRAY).
 */
$a = [1, 2];
$b = $a;
array_walk($a, function (&$v) {
    $v *= 10;
});
echo implode(',', $a), '|', implode(',', $b), "\n";
