<?php
/**
 * Thin AOT: sha1(..., true) raw digest must free on unset (#36388).
 * php-src: ext/standard/sha1.c PHP_FUNCTION(sha1).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = sha1('x'.$i, true);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'sha1_raw delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
