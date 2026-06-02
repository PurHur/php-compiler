--TEST--
str_decrement(): empty string throws ValueError (#3726)
--FILE--
<?php
try {
    str_decrement('');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
str_decrement(): Argument #1 ($string) must not be empty
