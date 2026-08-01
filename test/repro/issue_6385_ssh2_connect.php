<?php
// Repro for #6385 — ext/ssh2 phase-1 registration + connect-fail
declare(strict_types=1);

foreach (['ssh2_connect', 'ssh2_disconnect', 'ssh2_auth_password', 'ssh2_fingerprint', 'ssh2_exec', 'ssh2_fetch_stream'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
echo 'ext=', extension_loaded('ssh2') ? 'yes' : 'no', "\n";

if (!function_exists('ssh2_connect')) {
    echo "phantom_ok\n";
    exit(0);
}

$conn = @ssh2_connect('127.0.0.1', 1);
echo 'connect=', var_export($conn, true), "\n";
echo "ok\n";
