--TEST--
typed variadic named parameters — per-element checks on packed assoc array (#18647, Zend/zend_execute.c)
--FILE--
<?php
function f(int ...$args): int {
    return array_sum($args);
}
echo f(a: 1, b: 2, c: 3), "\n";
try {
    f(a: 'bad');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
6
Argument must be of type int, string given
