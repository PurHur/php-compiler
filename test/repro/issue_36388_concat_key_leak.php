<?php

/**
 * Ephemeral concat string keys must free after HT insert (#36388).
 *
 * `$a = ["k".$i => $i]; unset($a)` and `$a["k".$i] = $i` previously leaked
 * ~96 B/iter: setStringKey* `__string__separate`s into the node but never
 * delref'd the concat temp (php-src ZEND_ADD_ARRAY_ELEMENT / ASSIGN_DIM
 * release the temporary key zval after zend_hash_update).
 *
 * php-src: Zend/zend_vm_def.h ZEND_INIT_ARRAY / ZEND_ADD_ARRAY_ELEMENT /
 * ZEND_ASSIGN_DIM; Zend/zend_hash.c zend_hash_str_update.
 */
$n = (int) ($argv[1] ?? 2000);

$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $a = ["k".$i => $i];
    unset($a);
}
echo 'concat done n=', $n, ' peak=', memory_get_peak_usage(false),
    ' usage=', memory_get_usage(false), ' delta=', (memory_get_usage(false) - $u0), "\n";

$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $a = [$i => $i];
    unset($a);
}
echo 'packed done n=', $n, ' usage=', memory_get_usage(false),
    ' delta=', (memory_get_usage(false) - $u0), "\n";

$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $a = ['x' => $i];
    unset($a);
}
echo 'literal done n=', $n, ' usage=', memory_get_usage(false),
    ' delta=', (memory_get_usage(false) - $u0), "\n";
