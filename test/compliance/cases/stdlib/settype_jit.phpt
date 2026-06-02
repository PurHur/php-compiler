--TEST--
stdlib settype() JIT — in-place casts (issue #3151)
--FILE--
<?php
$x = '3';
settype($x, 'int');
echo $x, ' ', gettype($x), "\n";
--EXPECT--
3 integer
