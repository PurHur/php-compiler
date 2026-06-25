--TEST--
Function-local static persists through higher-order callback invoke (#11451, Zend/zend_execute.c)
--FILE--
<?php
function counter(): int {
    static $c = 0;
    return ++$c;
}
function invoke(callable $fn): int {
    return $fn();
}
echo invoke(fn () => counter()), "\n";
echo invoke(fn () => counter()), "\n";
--EXPECT--
1
2
