--TEST--
stdlib substr_compare()
--FILE--
<?php
echo substr_compare('abcde', 'bc', 1), "\n";
echo substr_compare('abcde', 'bc', 1, 2), "\n";
echo substr_compare('abc', 'abd', 0, 2), "\n";
echo substr_compare('abc', 'ABC', 0, 3, true), "\n";
echo substr_compare('abc', 'a', -3), "\n";
--EXPECT--
1
0
0
0
1
