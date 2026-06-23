--TEST--
str_decrement(): single-char underflow throws ValueError (#4847, ext/standard/string.c)
--FILE--
<?php
try {
    str_decrement('a');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
str_decrement(): Argument #1 ($string) "a" is out of decrement range
