<?php
/**
 * Thin AOT: str_pad BOTH / LEFT paths free on unset (#36388).
 * php-src: ext/standard/string.c PHP_FUNCTION(str_pad) STR_PAD_*.
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $a = str_pad('x'.$i, 10, '.', STR_PAD_LEFT);
    $b = str_pad('y'.$i, 10, '.', STR_PAD_BOTH);
    unset($a, $b);
}
$d = memory_get_usage(false) - $u0;
echo 'str_pad_sides delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
