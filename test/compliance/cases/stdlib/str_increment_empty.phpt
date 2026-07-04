--TEST--
str_increment(): empty string throws Error (#9277, php-src)
--FILE--
<?php
try {
    str_increment('');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
Error
str_increment(): Argument #1 ($string) must not be empty
