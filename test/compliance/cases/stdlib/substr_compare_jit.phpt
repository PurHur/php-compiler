--TEST--
JIT: substr_compare()
--FILE--
<?php
echo substr_compare('abcde', 'bc', 1, 2), "\n";
echo substr_compare('abc', 'ABC', 0, 3, true), "\n";
echo substr_compare('abc', 'a', -3, 1), "\n";
--EXPECT--
0
0
0
