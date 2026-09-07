<?php

/**
 * Thin AOT: strrev() result must free on unset (#36388).
 * php-src: ext/standard/string.c PHP_FUNCTION(strrev).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = strrev("hello");
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'strrev_lit delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
