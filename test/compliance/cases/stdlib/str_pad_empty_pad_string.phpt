--TEST--
str_pad(): empty $pad_string throws ValueError — must be a non-empty string (#3762 / #29755, php-src string.c)
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
str_pad(): Argument #3 ($pad_string) must be a non-empty string
