<?php
/**
 * Thin AOT: chunk_split() with non-literal input must free on unset (#36388).
 * php-src: ext/standard/string.c PHP_FUNCTION(chunk_split).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = chunk_split('abcdefghij'.$i, 4, ':');
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'chunk_local delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
