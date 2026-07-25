<?php

/**
 * Repro for #22704 — NumberFormatter ROUND_HALFODD / ROUND_UNNECESSARY profile gate.
 *
 * Default / PROFILE=8.2: HALFODD + UNNECESSARY + TOWARD/AWAY undefined (Zend 8.2).
 * PROFILE=8.4: HALFODD + TOWARD/AWAY defined; UNNECESSARY still undef (php-src stub).
 */
declare(strict_types=1);

$names = [
    'ROUND_HALFEVEN',
    'ROUND_HALFODD',
    'ROUND_UNNECESSARY',
    'ROUND_TOWARD_ZERO',
    'ROUND_AWAY_FROM_ZERO',
];
foreach ($names as $c) {
    $full = 'NumberFormatter::'.$c;
    echo $c, '=', defined($full) ? (string) constant($full) : 'undef', "\n";
}
