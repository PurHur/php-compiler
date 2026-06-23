--TEST--
stdlib hrtime() nanosecond precision — sub-microsecond nsec (#10859, ext/standard/hrtime.c)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    echo "skip\n";
}
?>
--FILE--
<?php
$anyNonZero = false;
for ($i = 0; $i < 10; $i++) {
    if (hrtime()[1] % 1000 !== 0) {
        $anyNonZero = true;
        break;
    }
}
echo $anyNonZero ? "mod1000\n" : "bad\n";
$a = hrtime()[1];
$b = hrtime()[1];
echo $a !== $b ? "vary\n" : "bad\n";
--EXPECT--
mod1000
vary
