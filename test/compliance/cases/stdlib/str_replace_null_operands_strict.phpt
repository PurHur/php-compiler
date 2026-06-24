--TEST--
stdlib str_replace() — null search TypeError under strict_types (#11014, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    str_replace(null, 'x', 'abc');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
str_replace(): Argument #1 ($search) must be of type array|string, null given
