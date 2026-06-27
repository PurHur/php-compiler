--TEST--
str_decrement(): single-char underflow throws Error (#4847, #12449, ext/standard/string.c)
--FILE--
<?php
try {
    str_decrement('a');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
Error
str_decrement(): Argument #1 ($string) "a" is out of decrement range
