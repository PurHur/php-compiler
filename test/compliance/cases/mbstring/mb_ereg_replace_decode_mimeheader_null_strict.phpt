--TEST--
mb_ereg_replace()/mb_decode_mimeheader() - null TypeError under strict_types (#30311)
--FILE--
<?php
declare(strict_types=1);
try {
    mb_ereg_replace(null, "b", "c");
    echo "FAIL: mb_ereg_replace coerced\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    mb_decode_mimeheader(null);
    echo "FAIL: mb_decode_mimeheader coerced\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
mb_ereg_replace(): Argument #1 ($pattern) must be of type string, null given
TypeError
mb_decode_mimeheader(): Argument #1 ($string) must be of type string, null given
