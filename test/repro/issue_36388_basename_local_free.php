<?php
/**
 * Thin AOT: basename() result must free on unset (#36388).
 * Single concat level (peer substr) — multi-concat chain leftovers are separate.
 * php-src: ext/standard/string.c php_basename / PHP_FUNCTION(basename).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = basename('hello'.$i);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'basename_local delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
