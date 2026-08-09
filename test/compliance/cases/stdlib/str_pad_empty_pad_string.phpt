--TEST--
str_pad(): empty $pad_string throws ValueError — must not be empty (#3762 / #29292, php-src string.c)
--FILE--
<?php
try {
    str_pad('a', 5, '');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
str_pad(): Argument #3 ($pad_string) must not be empty
