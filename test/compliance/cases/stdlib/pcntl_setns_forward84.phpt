--TEST--
stdlib pcntl_setns on PROFILE=8.4 (#21257, #26742, ext/pcntl/pcntl.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'pcntl_setns=', function_exists('pcntl_setns') ? 'Y' : 'N', "\n";
echo 'CLONE_NEWNET=', defined('CLONE_NEWNET') ? (string) CLONE_NEWNET : 'missing', "\n";

if (!function_exists('pcntl_setns')) {
    echo "skip\n";
    exit(0);
}

try {
    pcntl_setns(2147483646);
    echo "invalid_pid=no_throw\n";
} catch (ValueError $e) {
    echo 'invalid_pid=', str_contains($e->getMessage(), 'is not a valid process') ? 'Y' : $e->getMessage(), "\n";
}

$prev = error_reporting(E_ALL & ~E_USER_WARNING);
$ok = pcntl_setns(null, CLONE_NEWNET);
error_reporting($prev);
echo 'self_join=', ($ok === true) ? 'true' : 'false', "\n";
$err = function_exists('pcntl_get_last_error') ? pcntl_get_last_error() : 0;
echo 'last_error=', ($ok || $err > 0) ? 'ok' : 'unexpected', "\n";
?>
--EXPECTF--
pcntl_setns=Y
CLONE_NEWNET=1073741824
invalid_pid=Y
self_join=%s
last_error=ok
