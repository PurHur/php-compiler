--TEST--
AOT: array_multisort() two packed arrays couple without segfault (#26908)
--FILE--
<?php
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b);
echo implode(',', $a), '/', implode(',', $b), "\n";
--EXPECT--
1,2,3/a,b,c
