--TEST--
stdlib rsort() JIT integer list (#2300)
--FILE--
<?php
$a = array(3, 1, 2);
rsort($a);
echo implode(',', $a), "\n";
--EXPECT--
3,2,1
