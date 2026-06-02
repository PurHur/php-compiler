--TEST--
stdlib crc32() JIT — TypeError for non-string operand (#4594)
--FILE--
<?php
try {
    $unused = crc32([]);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
echo crc32('abc'), "\n";
--EXPECT--
TypeError: crc32(): Argument #1 ($string) must be of type string, array given
891568578
