--TEST--
stdlib settype() — in-place casts (ext/standard/type.c)
--FILE--
<?php
$x = '42';
settype($x, 'integer');
echo $x, ' ', gettype($x), "\n";

$y = 'hello';
settype($y, 'boolean');
echo (int) $y, ' ', gettype($y), "\n";

$z = '3.5';
settype($z, 'double');
echo $z, ' ', gettype($z), "\n";

$n = 1;
settype($n, 'null');
echo gettype($n), "\n";

$s = 99;
settype($s, 'string');
echo $s, ' ', gettype($s), "\n";

$a = 'item';
settype($a, 'array');
echo gettype($a), ' ', count($a), ' ', $a[0], "\n";

$empty = null;
settype($empty, 'array');
echo gettype($empty), ' ', count($empty), "\n";
--EXPECT--
42 integer
1 boolean
3.5 double
NULL
99 string
array 1 item
array 0
