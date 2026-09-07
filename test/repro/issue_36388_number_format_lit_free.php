<?php
/**
 * Thin AOT: number_format() compile-time args must free on unset (#36388).
 * php-src: ext/standard/math.c / number_format.c PHP_FUNCTION(number_format).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = number_format(1234.5, 2);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'number_format_lit delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
