--TEST--
stdlib pcntl_unshare/strerror/get_last_error (#20061, ext/pcntl/pcntl.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['pcntl_unshare', 'pcntl_strerror', 'pcntl_get_last_error'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}
echo 'PCNTL_ECHILD=', defined('PCNTL_ECHILD') ? (string) PCNTL_ECHILD : 'missing', "\n";
echo 'CLONE_NEWUSER=', defined('CLONE_NEWUSER') ? (string) CLONE_NEWUSER : 'missing', "\n";

if (!function_exists('pcntl_strerror') || !function_exists('pcntl_get_last_error')) {
    echo "skip\n";
    exit(0);
}

echo 'strerror_ECHILD=', pcntl_strerror(PCNTL_ECHILD), "\n";
echo 'last0=', pcntl_get_last_error(), "\n";

if (function_exists('pcntl_getpriority')) {
    @pcntl_getpriority(-1);
    $err = pcntl_get_last_error();
    echo 'last_nonzero=', ($err > 0) ? 'Y' : 'N', "\n";
    echo 'strerror_last=', pcntl_strerror($err), "\n";
}
?>
--EXPECT--
pcntl_unshare=Y
pcntl_strerror=Y
pcntl_get_last_error=Y
PCNTL_ECHILD=10
CLONE_NEWUSER=268435456
strerror_ECHILD=No child processes
last0=0
last_nonzero=Y
strerror_last=No such process
