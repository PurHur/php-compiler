--TEST--
stdlib str_pad(null) $pad_type under strict_types — TypeError (#29353, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
try {
    str_pad('a', 5, '.', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
str_pad(): Argument #4 ($pad_type) must be of type int, null given
