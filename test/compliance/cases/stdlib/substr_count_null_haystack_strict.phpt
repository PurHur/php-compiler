--TEST--
stdlib substr_count(null) under strict_types throws TypeError (#29808, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    substr_count(null, 'a');
    echo "bad: coerced\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    substr_count(null, 'a', 0);
    echo "bad: coerced with offset\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: substr_count(): Argument #1 ($haystack) must be of type string, null given
TypeError: substr_count(): Argument #1 ($haystack) must be of type string, null given
