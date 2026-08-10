--TEST--
mbstring mb_convert_encoding() — null $string TypeError under strict_types (#29777, ext/mbstring/mbstring.c)
--FILE--
<?php
declare(strict_types=1);
try {
    mb_convert_encoding(null, 'UTF-8');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
mb_convert_encoding(): Argument #1 ($string) must be of type array|string, null given
