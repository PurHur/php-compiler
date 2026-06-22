--TEST--
AOT: substr_compare() explicit $length (#10598)
--FILE--
<?php
echo substr_compare('hello', 'ell', 1, 10), "\n";
echo substr_compare('hello', 'ell', 1, 3), "\n";
--EXPECT--
1
0
