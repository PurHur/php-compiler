<?php

declare(strict_types=1);

/**
 * K-nucleotide (scaled) — substring counts in a hash map (#36385).
 */

$seq = str_repeat('GGTATTTTAATTTATAGT', 400);
$counts1 = [];
$n1 = strlen($seq);
for ($i = 0; $i < $n1; ++$i) {
    $frag = substr($seq, $i, 1);
    if (!isset($counts1[$frag])) {
        $counts1[$frag] = 0;
    }
    $counts1[$frag] = (int) $counts1[$frag] + 1;
}

$counts2 = [];
$n2 = $n1 - 1;
for ($i = 0; $i < $n2; ++$i) {
    $frag = substr($seq, $i, 2);
    if (!isset($counts2[$frag])) {
        $counts2[$frag] = 0;
    }
    $counts2[$frag] = (int) $counts2[$frag] + 1;
}

$a = isset($counts1['A']) ? (int) $counts1['A'] : 0;
$t = isset($counts1['T']) ? (int) $counts1['T'] : 0;
$gg = isset($counts2['GG']) ? (int) $counts2['GG'] : 0;
$ta = isset($counts2['TA']) ? (int) $counts2['TA'] : 0;
echo $a, '|', $t, '|', $gg, '|', $ta, "\n";
