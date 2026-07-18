<?php

/**
 * Repro for #20710 — NumberFormatter::ROUND_* constants.
 */
declare(strict_types=1);

$names = [
    'ROUND_CEILING', 'ROUND_FLOOR', 'ROUND_DOWN', 'ROUND_UP',
    'ROUND_HALFEVEN', 'ROUND_HALFDOWN', 'ROUND_HALFUP', 'ROUND_HALFODD',
    'ROUND_TOWARD_ZERO', 'ROUND_AWAY_FROM_ZERO',
];
foreach ($names as $c) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? (string) constant($full) : 'undef', "\n";
}
