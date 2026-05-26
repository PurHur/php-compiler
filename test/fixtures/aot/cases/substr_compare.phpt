--TEST--
AOT: substr_compare() (#2400)
--FILE--
<?php
echo substr_compare('abcde', 'bc', 1, 2), "\n";
echo substr_compare('abc', 'ABC', 0, 3, true), "\n";
--EXPECT--
0
0
