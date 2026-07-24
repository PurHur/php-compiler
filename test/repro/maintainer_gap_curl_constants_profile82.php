<?php

declare(strict_types=1);

/**
 * Repro for #22837 — CURLOPT/CURLINFO profile advertisement vs Zend 8.2.
 *
 * need_* must be defined on default / PROFILE=8.2; phantom_* must be undefined
 * unless PHP_COMPILER_PROFILE=8.4 (or later).
 */
$need = [
    'CURLOPT_MAXFILESIZE',
    'CURLOPT_MAXFILESIZE_LARGE',
    'CURLOPT_HSTS',
    'CURLOPT_HSTS_CTRL',
    'CURLOPT_ALTSVC',
    'CURLOPT_ALTSVC_CTRL',
    'CURLOPT_AWS_SIGV4',
    'CURLOPT_CAINFO_BLOB',
    'CURLOPT_HAPROXYPROTOCOL',
    'CURLINFO_REFERER',
    'CURLINFO_RETRY_AFTER',
];
$phantom = [
    'CURLOPT_TCP_KEEPCNT',
    'CURLOPT_PREREQFUNCTION',
    'CURLOPT_SERVER_RESPONSE_TIMEOUT',
];

foreach ($need as $c) {
    echo 'need_', $c, '=', defined($c) ? 'y' : 'n', "\n";
}
foreach ($phantom as $c) {
    echo 'phantom_', $c, '=', defined($c) ? 'y' : 'n', "\n";
}
