--TEST--
JIT substr_compare() explicit $length longer than needle (#10598, ext/standard/string.c)
--FILE--
<?php
echo substr_compare('hello', 'ell', 1, 10), "\n";
echo substr_compare('hello', 'ell', 1, 3), "\n";
echo substr_compare('hello', 'el', 1, 4), "\n";
--EXPECT--
1
0
1
