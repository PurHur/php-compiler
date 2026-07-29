--TEST--
str_increment(): empty string throws ValueError (#18061, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
try {
    str_increment('');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
str_increment(): Argument #1 ($string) must not be empty
