<?php
// Repro for #36215: dropping arrays must release nested strings/arrays, not leak headers only.

function mk($i)
{
    return ['k'.$i => str_repeat('x', 100), 'n' => [$i, $i + 1]];
}

for ($i = 0; $i < 200000; $i++) {
    $a = mk($i);
}
$r = getrusage();
echo 'done maxrss_kb='.(int) ($r['ru_maxrss'] ?? 0)."\n";
