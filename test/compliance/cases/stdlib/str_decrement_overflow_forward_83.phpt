--TEST--
str_decrement(): single-char underflow throws ValueError (#16864, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
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
