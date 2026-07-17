<?php
foreach (['pcntl_unshare', 'pcntl_strerror', 'pcntl_get_last_error'] as $f) {
    echo $f, '=', function_exists($f) ? 1 : 0, "\n";
}
if (!function_exists('pcntl_strerror')) {
    echo "MISSING\n";
    exit(0);
}
echo 'ECHILD=', defined('PCNTL_ECHILD') ? (string) PCNTL_ECHILD : 'missing', "\n";
echo 'strerror=', pcntl_strerror(PCNTL_ECHILD), "\n";
echo 'last0=', pcntl_get_last_error(), "\n";
if (function_exists('pcntl_getpriority')) {
    @pcntl_getpriority(-1);
    echo 'last_after=', pcntl_get_last_error(), "\n";
    echo 'strerror_after=', pcntl_strerror(pcntl_get_last_error()), "\n";
}
