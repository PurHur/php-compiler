<?php
/**
 * #26969 — thin AOT array_walk() by-ref Closure must mutate in place (no segfault).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_walk) / php_array_walk
 */
$a = [1, 2];
array_walk($a, function (&$v) {
    $v++;
});
echo implode(',', $a), "\n";
