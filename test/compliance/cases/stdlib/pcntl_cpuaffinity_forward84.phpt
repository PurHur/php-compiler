--TEST--
pcntl_getcpuaffinity/setcpuaffinity/getcpu on PROFILE=8.4 (#20510, #26742)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php if (!function_exists('pcntl_getcpuaffinity')) die('skip no pcntl affinity'); ?>
--FILE--
<?php
declare(strict_types=1);

foreach (['pcntl_getcpuaffinity', 'pcntl_setcpuaffinity', 'pcntl_getcpu'] as $f) {
    echo $f, ' ', function_exists($f) ? 'Y' : 'N', "\n";
}

$cpus = pcntl_getcpuaffinity();
echo 'get ', is_array($cpus) && count($cpus) > 0 ? 'ok' : 'bad', ' n=', is_array($cpus) ? count($cpus) : 0, "\n";

$cpu = pcntl_getcpu();
echo 'getcpu ', is_int($cpu) && $cpu >= 0 ? 'ok' : 'bad', "\n";

$first = $cpus[0];
$ok = pcntl_setcpuaffinity(null, [$first]);
echo 'set ', $ok ? '1' : '0', "\n";
$after = pcntl_getcpuaffinity();
echo 'roundtrip ', (is_array($after) && in_array($first, $after, true)) ? 'ok' : 'bad', "\n";

// restore full mask when possible
@pcntl_setcpuaffinity(null, $cpus);

try {
    pcntl_setcpuaffinity(null, []);
    echo "empty-bad\n";
} catch (ValueError $e) {
    echo str_contains($e->getMessage(), 'must not be empty') ? "empty-ok\n" : "empty-msg\n";
}

try {
    pcntl_getcpuaffinity(2147483646);
    echo "badpid-bad\n";
} catch (ValueError $e) {
    echo str_contains($e->getMessage(), 'invalid process') ? "badpid-ok\n" : "badpid-msg\n";
}
?>
--EXPECTF--
pcntl_getcpuaffinity Y
pcntl_setcpuaffinity Y
pcntl_getcpu Y
get ok n=%d
getcpu ok
set 1
roundtrip ok
empty-ok
badpid-ok
