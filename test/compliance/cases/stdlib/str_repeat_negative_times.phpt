--TEST--
str_repeat(): negative $times throws ValueError (#3735)
--FILE--
<?php
try {
    str_repeat('x', -1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
str_repeat(): Argument #2 ($times) must be greater than or equal to 0
