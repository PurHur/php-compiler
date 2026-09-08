<?php
/**
 * Thin AOT: hash(..., true) raw digest must free on unset (#36388).
 * php-src: ext/hash/hash.c PHP_FUNCTION(hash).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = hash('sha256', 'x'.$i, true);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'hash_raw delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
