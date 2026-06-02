--TEST--
stdlib crc32() — TypeError for non-string operand (#4594, ext/standard/string.c)
--FILE--
<?php
try {
    $unused = crc32([]);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
try {
    $unused = crc32(new stdClass());
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo crc32('abc'), "\n";
echo crc32(123), "\n";
--EXPECT--
TypeError: crc32(): Argument #1 ($string) must be of type string, array given
TypeError: crc32(): Argument #1 ($string) must be of type string, stdClass given
891568578
2286445522
