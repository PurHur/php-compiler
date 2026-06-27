<?php

declare(strict_types=1);

// Repro for #12573 — valid IPv4 with no PTR: return IP, no E_WARNING (php-src ext/standard/dns.c).
error_reporting(E_ALL);
ini_set('display_errors', '1');

$ip = '10.0.0.1';
ob_start();
$result = gethostbyaddr($ip);
$warnings = ob_get_clean();

if ('' !== $warnings) {
    echo "fail: unexpected warnings:\n{$warnings}\n";
    exit(1);
}
if (!is_string($result) || $result !== $ip) {
    echo 'fail: expected unmodified ip, got '.var_export($result, true)."\n";
    exit(1);
}
if ('localhost' !== gethostbyaddr('127.0.0.1')) {
    echo "fail: 127.0.0.1 should resolve to localhost\n";
    exit(1);
}
echo "ok\n";
