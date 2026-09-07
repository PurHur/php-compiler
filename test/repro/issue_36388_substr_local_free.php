<?php

/**
 * Thin AOT: substr() result must free on unset (#36388).
 * php-src: ext/standard/string.c php_substr / PHP_FUNCTION(substr).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = substr('hello' . $i, 1, 3);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'substr_local delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
