--TEST--
AOT: array_pad() right and left pad
--FILE--
<?php
$a = array_pad(array(1, 2), 5, 'p');
echo count($a), ':', $a[2], '|', $a[4], "\n";
$b = array_pad(array(9), -3, 0);
echo count($b), ':', $b[0], '|', $b[2], "\n";
--EXPECT--
5:p|p
3:0|9
