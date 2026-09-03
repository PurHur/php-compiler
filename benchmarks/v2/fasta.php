<?php

declare(strict_types=1);

/**
 * Fasta (scaled) — string generation via integer RNG (#36385).
 */

$alphabet = 'acgtBDHKMNRSVWY';
$n = 3000;
$seed = 42;
$ia = 3877;
$ic = 29573;
$im = 139968;
$alphaLen = strlen($alphabet);
$out = '';
$line = '';
for ($i = 0; $i < $n; ++$i) {
    $seed = ($seed * $ia + $ic) % $im;
    $line .= $alphabet[$seed % $alphaLen];
    if (strlen($line) === 60) {
        $out .= $line."\n";
        $line = '';
    }
}
if ('' !== $line) {
    $out .= $line."\n";
}

echo strlen($out), '|', substr($out, 0, 20), "\n";
