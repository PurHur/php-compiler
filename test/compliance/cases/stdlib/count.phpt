--TEST--
stdlib count() for arrays
--FILE--
<?php
$a = array(1, 2, 3);
echo count($a), "\n";
echo count(array()), "\n";
--EXPECT--
3
0
