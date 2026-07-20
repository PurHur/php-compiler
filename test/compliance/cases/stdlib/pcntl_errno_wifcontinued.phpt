--TEST--
stdlib pcntl_errno/wifcontinued/sigwaitinfo (#21330, ext/pcntl/pcntl.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['pcntl_errno', 'pcntl_wifcontinued', 'pcntl_sigwaitinfo', 'pcntl_get_last_error'] as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', "\n";
}

if (!function_exists('pcntl_wifcontinued') || !function_exists('pcntl_errno')) {
    echo "skip\n";
    exit(0);
}

$continued = 0xffff;
echo 'wifcontinued=', (int) pcntl_wifcontinued($continued), "\n";
echo 'wifcontinued_exit=', (int) pcntl_wifcontinued(0), "\n";

echo 'errno0=', pcntl_errno(), "\n";
echo 'errno_alias=', (int) (pcntl_errno() === pcntl_get_last_error()), "\n";

if (function_exists('pcntl_getpriority')) {
    @pcntl_getpriority(-1);
    echo 'errno_after=', pcntl_errno(), "\n";
    echo 'errno_matches=', (int) (pcntl_errno() === pcntl_get_last_error()), "\n";
}
?>
--EXPECT--
pcntl_errno=Y
pcntl_wifcontinued=Y
pcntl_sigwaitinfo=Y
pcntl_get_last_error=Y
wifcontinued=1
wifcontinued_exit=0
errno0=0
errno_alias=1
errno_after=3
errno_matches=1
