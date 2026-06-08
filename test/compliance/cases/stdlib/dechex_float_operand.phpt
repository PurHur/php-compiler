--TEST--
stdlib dechex()/decbin()/decoct() float operand truncation (#4211, ext/standard/math.c)
--FILE--
<?php
echo dechex(1.9), "\n";
echo decbin(2.9), "\n";
echo decoct(7.9), "\n";
echo dechex(255), "\n";
try {
    dechex([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
10
7
ff
dechex(): Argument #1 ($num) must be of type int, array given
