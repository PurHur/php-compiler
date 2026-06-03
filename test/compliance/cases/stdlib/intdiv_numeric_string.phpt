--TEST--
stdlib intdiv() — numeric-string operands (#4982)
--FILE--
<?php
echo intdiv('12', '3'), "\n";
try {
    intdiv('x', 1);
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
4
TypeError
intdiv(): Argument #1 ($num1) must be of type int, string given
