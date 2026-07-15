--TEST--
stdlib crc32c() null operand TypeError JIT (PHP 8.4, ext/standard/crc32c.c)
--FILE--
<?php
try {
    crc32c(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
crc32c(): Argument #1 ($string) must be of type string, null given
