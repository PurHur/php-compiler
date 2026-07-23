<?php
/**
 * #22653 — serialize() of `$a=[1]; $a[]=&$a` must match php-src R: graph
 * (ext/standard/var.c php_var_serialize / php_add_var_hash).
 */
$a = [1];
$a[] = &$a;
echo serialize($a), "\n";
