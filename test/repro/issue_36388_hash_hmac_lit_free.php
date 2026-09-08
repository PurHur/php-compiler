<?php
/**
 * Thin AOT: hash_hmac() with literal input must free on unset (#36388).
 * php-src: ext/hash/hash.c PHP_FUNCTION(hash_hmac).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = hash_hmac('sha256', 'fixed', 'secret');
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'hmac_lit delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
