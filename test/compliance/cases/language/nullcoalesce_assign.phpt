--TEST--
Null coalesce assign (??=) operator (#5635 VMTest provider hygiene)
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
NULL
int(5)
NULL
NULL
int(1)
int(1)
