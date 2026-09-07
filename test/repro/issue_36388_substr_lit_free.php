<?php

/**
 * Thin AOT: substr() on a literal must free (or immortal no-op) on unset (#36388).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = substr('hello', 1, 3);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'substr_lit delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
