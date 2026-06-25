--TEST--
stdlib clock_gettime() / ClockInterface enum (PHP 8.3, issue #11624, ext/standard/hrtime.c)
--FILE--
<?php
declare(strict_types=1);
echo (int) function_exists('clock_gettime'), "\n";
echo (int) enum_exists('ClockInterface'), "\n";
$rt = clock_gettime();
echo is_array($rt) && isset($rt['sec'], $rt['nsec']) ? 'shape-ok' : 'shape-bad', "\n";
echo $rt['sec'] >= 0 ? 'sec-ok' : 'sec-bad', "\n";
$mono = clock_gettime(ClockInterface::Monotonic);
echo is_array($mono) && isset($mono['sec'], $mono['nsec']) ? 'mono-ok' : 'mono-bad', "\n";
--EXPECT--
1
1
shape-ok
sec-ok
mono-ok
