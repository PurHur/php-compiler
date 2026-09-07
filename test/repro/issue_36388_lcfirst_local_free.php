<?php

/**
 * Thin AOT: lcfirst() result must free on unset (#36388).
 * php-src: ext/standard/string.c PHP_FUNCTION(lcfirst).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = lcfirst("HELLO".$i);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'lcfirst_local delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
