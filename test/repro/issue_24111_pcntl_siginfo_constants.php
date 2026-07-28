<?php
declare(strict_types=1);
/**
 * Repro for #24111 — pcntl SI_/CLD_/SIGRT/SIGCLD/SIGIOT vs Zend (php-src-strict).
 */
$names = [
    'SI_USER', 'CLD_EXITED', 'SIGRTMIN', 'SIGRTMAX', 'SIGCLD', 'SIGIOT', 'SIGTERM',
];
foreach ($names as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'UNDEF', "\n";
}
