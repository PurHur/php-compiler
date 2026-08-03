--TEST--
AOT: SplFixedArray count/getSize ignore null-pad overwrite (#27285)
--FILE--
<?php
$a = new SplFixedArray(3);
$a[0] = 1;
$a[2] = 3;
echo $a[0], ',', $a[2], ',', count($a), "\n";
echo $a->getSize(), "\n";
--EXPECT--
1,3,3
3
