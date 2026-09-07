<?php
/**
 * sprintf() string keys must free under thin AOT after HT insert + unset (#36388).
 *
 * php-src: Zend/zend_vm_def.h ZEND_INIT_ARRAY / ZEND_ADD_ARRAY_ELEMENT release
 * temporary key zvals after zend_hash_update owns a copy.
 */
function sprintf_key_free(int $n): void {
    $u0 = memory_get_usage(false);
    for ($i = 0; $i < $n; $i++) {
        $k = sprintf('k%d', $i);
        $a = [$k => $i];
        unset($a, $k);
    }
    $u1 = memory_get_usage(false);
    $delta = $u1 - $u0;
    echo 'sprintf_key delta=', $delta, ($delta <= 256 ? " ok\n" : " LEAK\n");
}
sprintf_key_free((int) ($argv[1] ?? 2000));
