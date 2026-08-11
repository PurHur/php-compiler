--TEST--
Language: $a[] after negative-only keys continues nNextFreeElement (zend_hash.c / #30052)
--FILE--
<?php
$a = [];
$a[-2] = 1;
$a[] = 2;
echo implode(',', array_keys($a)), ':', implode(',', $a), "\n";

$b = [];
$b[-5] = 1;
$b[] = 2;
$b[] = 3;
echo implode(',', array_keys($b)), ':', implode(',', $b), "\n";

$c = [-1 => 1];
$c[] = 2;
echo implode(',', array_keys($c)), ':', implode(',', $c), "\n";

$d = [0 => 1];
$d[-2] = 2;
$d[] = 3;
echo implode(',', array_keys($d)), ':', implode(',', $d), "\n";
?>
--EXPECT--
-2,-1:1,2
-5,-4,-3:1,2,3
-1,0:1,2
0,-2,1:1,2,3
