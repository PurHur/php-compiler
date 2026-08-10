--TEST--
stdlib strstr() JIT — null $needle TypeError under strict_types (#29766, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    strstr('abc', null);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
strstr(): Argument #2 ($needle) must be of type string, null given
