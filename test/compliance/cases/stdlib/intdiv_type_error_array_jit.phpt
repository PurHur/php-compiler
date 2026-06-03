--TEST--
stdlib intdiv() JIT — TypeError for array operand (#4982)
--FILE--
<?php
try {
    intdiv([], 1);
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
intdiv(): Argument #1 ($num1) must be of type int, array given
