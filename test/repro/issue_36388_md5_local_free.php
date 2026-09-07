<?php
/**
 * Thin AOT: md5() with non-literal input must free on unset (#36388).
 * php-src: ext/standard/md5.c PHP_FUNCTION(md5).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = md5('x'.$i);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'md5_local delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
