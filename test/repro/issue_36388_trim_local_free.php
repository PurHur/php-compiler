<?php

/**
 * Thin AOT: trim() result must free on unset (#36388).
 * php-src: ext/standard/string.c php_trim / PHP_FUNCTION(trim).
 *
 * Single concat haystack — multi-concat ephemeral chain links are a follow-up.
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = trim('  x' . $i);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'trim_local delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
