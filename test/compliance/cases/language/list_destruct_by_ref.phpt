--TEST--
list() / [] destructuring by-reference (issue #3342, Zend parity)
--FILE--
<?php
$a = [1, 2];
list(&$x, $y) = $a;
$x = 9;
echo $a[0], "\n";

$b = [3, 4];
[&$u, $v] = $b;
$u = 7;
echo $b[0], "\n";
--EXPECT--
9
7
