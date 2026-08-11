--TEST--
stdlib readlink(null) JIT TypeError under strict_types (#30168, ext/standard/link.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    readlink(null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
readlink(): Argument #1 ($path) must be of type string, null given
