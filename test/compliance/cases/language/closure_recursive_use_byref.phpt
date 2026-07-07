--TEST--
language: closure use (&$fn) recursive call resolves callee as closure not function name (#17089)
--FILE--
<?php
$fib = function ($n) use (&$fib) {
    return $n <= 1 ? $n : $fib($n - 1) + $fib($n - 2);
};
echo $fib(5), "\n";

$g = function ($i) use (&$g) {
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
