<?php
// @differential-repeat: 10 COW next/end must separate before pointer mutate (#36397)
/**
 * By-value `$b = $a` shares the hashtable; next($a)/end($a) must not move $b's
 * internal pointer (php-src zend_parse `a/` → SEPARATE_ARRAY on next/prev/reset/end).
 */
$a = [10, 20, 30];
$b = $a;
$n = next($a);
echo var_export($n, true), '|', var_export(current($a), true), '|', var_export(current($b), true), '|';
echo var_export(key($a), true), '|', var_export(key($b), true), '|';
$e = end($a);
echo var_export($e, true), '|', var_export(current($a), true), '|', var_export(current($b), true), '|';
echo var_export(key($a), true), '|', var_export(key($b), true), "\n";
