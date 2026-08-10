--TEST--
mbstring mb_chr()/mb_ord() - null TypeError under strict_types (#29778, ext/mbstring/mbstring.c)
--FILE--
<?php
declare(strict_types=1);
try {
    mb_chr(null);
    echo "FAIL: mb_chr coerced\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    mb_ord(null);
    echo "FAIL: mb_ord coerced\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
mb_chr(): Argument #1 ($codepoint) must be of type int, null given
TypeError
mb_ord(): Argument #1 ($string) must be of type string, null given
