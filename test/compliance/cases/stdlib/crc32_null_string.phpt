--TEST--
stdlib crc32() null $string under strict_types — TypeError (#16115, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    var_export(crc32(null));
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: crc32(): Argument #1 ($string) must be of type string, null given
