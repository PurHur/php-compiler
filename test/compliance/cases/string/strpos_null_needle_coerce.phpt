--TEST--
strpos()/stripos() — null needle coerces to empty string (#18345, ext/standard/string.c)
--FILE--
<?php
echo strpos('abc', null), "\n";
echo stripos('abc', null), "\n";
?>
--EXPECT--
0
0
