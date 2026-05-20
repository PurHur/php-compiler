--TEST--
AOT: array_sum() for packed lists
--FILE--
<?php
echo array_sum(array()), "\n";
echo array_sum(array(1, 2, 3)), "\n";
echo array_sum(array(1.5, 2.5)), "\n";
--EXPECT--
0
6
4
