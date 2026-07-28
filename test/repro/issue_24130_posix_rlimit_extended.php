<?php
declare(strict_types=1);
/**
 * Repro for #24130 — Linux extended POSIX_RLIMIT_* vs Zend (php-src-strict).
 */
$names = [
    'POSIX_RLIMIT_LOCKS',
    'POSIX_RLIMIT_SIGPENDING',
    'POSIX_RLIMIT_MSGQUEUE',
    'POSIX_RLIMIT_NICE',
    'POSIX_RLIMIT_RTPRIO',
    'POSIX_RLIMIT_RTTIME',
];
foreach ($names as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}
