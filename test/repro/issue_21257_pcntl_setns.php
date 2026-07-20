<?php
foreach (['pcntl_setns', 'pcntl_unshare'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'MISSING', "\n";
}
echo 'CLONE_NEWNET=', defined('CLONE_NEWNET') ? (string) CLONE_NEWNET : 'missing', "\n";
if (!function_exists('pcntl_setns')) {
    exit(0);
}
try {
    pcntl_setns(2147483646);
    echo "invalid_pid=no_throw\n";
} catch (ValueError $e) {
    echo 'invalid_pid_ok=', str_contains($e->getMessage(), 'is not a valid process') ? '1' : '0', "\n";
}
$prev = error_reporting(E_ALL & ~E_USER_WARNING);
$ok = pcntl_setns();
error_reporting($prev);
echo 'call_ok=', $ok ? '1' : '0', "\n";
if (function_exists('pcntl_get_last_error')) {
    echo 'last_error=', pcntl_get_last_error(), "\n";
}
