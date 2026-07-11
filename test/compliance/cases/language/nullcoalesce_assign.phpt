--TEST--
Null coalesce assign (??=) operator (#5635, #17458 chained expression value)
--FILE--
<?php
$a = null;
var_dump($a ??= 5, $a);
$b = $a ??= 5;
var_dump($b);

$x = null;
$y = null;
var_dump($x ??= $y ??= 1, $x, $y);
--EXPECT--
int(5)
int(5)
int(5)
int(1)
int(1)
int(1)
