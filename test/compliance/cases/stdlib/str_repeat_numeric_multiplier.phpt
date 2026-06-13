--TEST--
stdlib str_repeat() — numeric-string and float $times coercion (#4171, ext/standard/string.c)
--FILE--
<?php
echo str_repeat('x', 2.9), "\n";
echo str_repeat('y', '3'), "\n";
try {
    str_repeat('x', -1);
} catch (ValueError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
xx
yyy
ValueError: str_repeat(): Argument #2 ($times) must be greater than or equal to 0
