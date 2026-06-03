--TEST--
language: arrow function with by-reference parameter (issue #5023, zend_closures.c)
--FILE--
<?php
$f = fn (&$x) => $x;
$x = 1;
$f($x);
echo $x, "\n";
$g = fn (&$a, &$b) => $a + $b;
$a = 2;
$b = 3;
echo $g($a, $b), "\n";
--EXPECT--
1
5
