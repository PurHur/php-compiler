<?php
/**
 * Thin AOT: htmlspecialchars() literal input must free on unset (#36388).
 */
$n = (int) ($argv[1] ?? 2000);
$u0 = memory_get_usage(false);
for ($i = 0; $i < $n; $i++) {
    $s = htmlspecialchars('a<b&c>"\'', ENT_QUOTES | ENT_SUBSTITUTE);
    unset($s);
}
$d = memory_get_usage(false) - $u0;
echo 'hs_lit delta=', $d, ' ', ($d === 0 ? 'ok' : 'LEAK'), "\n";
