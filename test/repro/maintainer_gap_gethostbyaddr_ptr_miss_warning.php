<?php

declare(strict_types=1);

// Repro for #12573 — valid IPv4 with no PTR returns unmodified address, no E_WARNING (php-src).
$ip = '10.0.0.1';
$result = gethostbyaddr($ip);
if (is_string($result) && $result === $ip) {
    echo "ok\n";
} else {
    echo 'fail: expected unmodified ip, got '.var_export($result, true)."\n";
}
