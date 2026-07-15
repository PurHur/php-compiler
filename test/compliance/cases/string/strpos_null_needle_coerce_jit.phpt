--TEST--
strpos()/stripos() — null needle coerces to empty string JIT (#18345, ext/standard/string.c)
--JIT--
--FILE--
<?php
echo strpos('abc', null), "\n";
echo stripos('abc', null), "\n";
?>
--EXPECT--
0
0
