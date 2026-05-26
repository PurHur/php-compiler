--TEST--
stdlib rsort() on integer list arrays (#2300)
--FILE--
<?php
$a = [3, 1, 2];
rsort($a);
echo implode(',', $a), "\n";
--EXPECT--
3,2,1
