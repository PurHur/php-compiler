--TEST--
posix_times()/posix_setsid() — JIT no fatal (issue #9218)
--SKIPIF--
<?php
if (!function_exists('posix_times') || !function_exists('posix_setsid')) {
    die('skip host ext-posix unavailable');
}
?>
--FILE--
<?php
declare(strict_types=1);
$t = posix_times();
echo is_array($t) ? 'times_array' : gettype($t), "\n";
echo array_key_exists('ticks', $t) ? 'ticks_ok' : 'ticks_missing', "\n";
$sid = posix_setsid();
echo is_int($sid) ? 'sid_int' : gettype($sid), "\n";
?>
--EXPECT--
times_array
ticks_ok
sid_int
