--TEST--
mbstring mb_split() - null $pattern/$string TypeError under strict_types (#29811, php_mbregex.c)
--FILE--
<?php
declare(strict_types=1);
try {
    mb_split(null, 'a');
    echo "FAIL: pattern coerced\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    mb_split(',', null);
    echo "FAIL: string coerced\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
mb_split(): Argument #1 ($pattern) must be of type string, null given
TypeError
mb_split(): Argument #2 ($string) must be of type string, null given
