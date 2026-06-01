--TEST--
typed variadic parameters — per-element type checks (Zend zend_type_check.c, #4185)
--FILE--
<?php
function f(int ...$x) {
    return array_sum($x);
}
echo f(1, 2, 3), "\n";
try {
    f('bad');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
6
Argument must be of type int, string given
