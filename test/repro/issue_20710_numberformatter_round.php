<?php

/**
 * Repro for #20710 / #22704 — NumberFormatter::ROUND_* on forward PROFILE=8.4.
 */
declare(strict_types=1);

$names = [
    'ROUND_CEILING', 'ROUND_FLOOR', 'ROUND_DOWN', 'ROUND_UP',
    'ROUND_HALFEVEN', 'ROUND_HALFDOWN', 'ROUND_HALFUP', 'ROUND_HALFODD',
    'ROUND_TOWARD_ZERO', 'ROUND_AWAY_FROM_ZERO', 'ROUND_UNNECESSARY',
];
foreach ($names as $c) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? (string) constant($full) : 'undef', "\n";
}
