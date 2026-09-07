<?php

/**
 * Thin AOT: strtoupper() on a literal must free on unset (#36388).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = strtoupper('abc');
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'strtoupper_lit delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
