--TEST--
stdlib hrtime() — sub-microsecond nanoseconds via clock_gettime (issue #12225, ext/standard/hrtime.c)
--FILE--
<?php
declare(strict_types=1);
$any = false;
for ($i = 0; $i < 10; ++$i) {
    if (0 !== hrtime()[1] % 1000) {
        $any = true;
        break;
    }
}
echo $any ? 'nsec-ok' : 'nsec-bad', "\n";
$mono = clock_gettime(ClockInterface::Monotonic);
echo is_array($mono) && 0 !== ($mono['nsec'] % 1000) ? 'clock-ok' : 'clock-bad', "\n";
--EXPECT--
nsec-ok
clock-ok
