--TEST--
str_decrement(): empty string throws Error (#9277, php-src)
--FILE--
<?php
try {
    str_decrement('');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
Error
str_decrement(): Argument #1 ($string) must not be empty
