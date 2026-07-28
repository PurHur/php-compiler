--TEST--
Function and builtin first-class callable (AOT, #24166)
--FILE--
<?php
function dbl(int $n): int {
    return $n * 2;
}
$f = dbl(...);
echo $f(21), "\n";
$c = strlen(...);
echo $c("hello"), "\n";
echo (strlen(...))("hi"), "\n";
--EXPECT--
42
5
2
