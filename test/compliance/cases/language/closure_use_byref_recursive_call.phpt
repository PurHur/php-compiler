--TEST--
language: closure use (&$fn) recursive call invokes closure not function name (#17089, Zend/zend_closures.c)
--FILE--
<?php
$fib = function (int $n) use (&$fib): int {
    return $n <= 1 ? $n : $fib($n - 1) + $fib($n - 2);
};
echo $fib(5), "\n";

$g = function (int $i) use (&$g): void {
    if ($i >= 3) {
        return;
    }
    echo $i;
    $g($i + 1);
};
$g(0);
echo "\n";
--EXPECT--
5
012
