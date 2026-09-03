<?php

/**
 * Packed list literals with untyped loop vars must not heap-promote every iteration (#36388).
 *
 * `$a = [$i]` was inferred int[] but `$i` is TYPE_VALUE → promoteNativeArrayVariableToHashtable
 * on every INIT_ARRAY, leaving HT rc=2 so reassign never freed (~240 B/iter).
 *
 * php-src: Zend/zend_execute.c zend_assign_to_variable; zend_hash_* packed arrays stay
 * request-arena / zval-owned — no per-literal orphan malloc.
 */
$n = (int) ($argv[1] ?? 20000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $a = [$i];
    unset($a);
}
$peak = memory_get_peak_usage(false);
$usage = memory_get_usage(false);
echo 'done n=', $n, ' peak=', $peak, ' usage=', $usage, ' delta=', ($usage - $u0), "\n";
