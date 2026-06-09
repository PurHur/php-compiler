--TEST--
stdlib fputcsv() JIT — empty enclosure ValueError (#4530, ext/standard/file.c)
--JIT--
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
try {
    fputcsv($fp, ['a'], ',', '', '\\');
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ValueError: fputcsv(): Argument #4 ($enclosure) must be a single character
