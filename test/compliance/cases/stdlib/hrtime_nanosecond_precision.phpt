--TEST--
stdlib hrtime() nanosecond precision — sub-microsecond nsec (#10859, #24870, ext/standard/hrtime.c)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    echo "skip\n";
}
?>
--FILE--
<?php
// Sub-µs digits come from a microtime overlay on /proc/uptime (#10859). Assert over a
// wider sample window so exact-µs landings do not flake the suite (#24870).
$anyNonZero = false;
for ($i = 0; $i < 64; $i++) {
    if (hrtime()[1] % 1000 !== 0) {
        $anyNonZero = true;
        break;
    }
}
echo $anyNonZero ? "mod1000\n" : "bad\n";
// Consecutive pair[1] samples often match when the monotonic clamp holds or when the
// nsec-within-second component coincides. Poll total ns until the clock advances.
$a = hrtime(true);
$b = $a;
for ($i = 0; $i < 10000; $i++) {
    $b = hrtime(true);
    if ($b !== $a) {
        break;
    }
}
echo $b !== $a ? "vary\n" : "bad\n";
--EXPECT--
mod1000
vary
