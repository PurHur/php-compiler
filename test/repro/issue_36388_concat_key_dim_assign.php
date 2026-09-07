<?php

/**
 * Dim-assign with ephemeral concat key must not leak (#36388).
 *
 * php-src: Zend/zend_vm_def.h ZEND_ASSIGN_DIM.
 */
$n = (int) ($argv[1] ?? 1000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $a = [];
    $a["k".$i] = $i;
    unset($a);
}
echo 'dim delta=', (memory_get_usage(false) - $u0), "\n";
