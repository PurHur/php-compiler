--TEST--
AOT: shuffle() packed list permutation (#2310)
--FILE--
<?php
$b = array();
$b[] = 3;
$b[] = 1;
$b[] = 2;
shuffle($b);
echo count($b), ':', $b[0] + $b[1] + $b[2], "\n";
--EXPECT--
3:6
