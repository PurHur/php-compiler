--TEST--
stdlib fgetcsv() JIT — separator/enclosure ValueError parity (#12018, ext/standard/file.c)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);
foreach ([
    ['', '"', '\\'],
    [',', '', '\\'],
] as [$separator, $enclosure, $escape]) {
    rewind($f);
    try {
        fgetcsv($f, 0, $separator, $enclosure, $escape);
        echo "no throw\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
ValueError: fgetcsv(): Argument #3 ($separator) must be a single character
ValueError: fgetcsv(): Argument #4 ($enclosure) must be a single character
