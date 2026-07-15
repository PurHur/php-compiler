--TEST--
Language: user-function TypeError includes function name and call site (issues #18853, Zend/zend_execute.c)
--FILE--
<?php
function f(int ...$args): int {
    return array_sum($args);
}

echo f(a: 1, b: 2, c: 3), "\n";

try {
    f(['a' => 1]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    f(a: 'bad');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECTF--
6
f(): Argument #1 must be of type int, array given, called in %s on line %d
f(): Argument #1 must be of type int, string given, called in %s on line %d
