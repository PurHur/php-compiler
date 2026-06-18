--TEST--
JIT: sort()/rsort() SORT_NUMERIC on string elements (#4076, #9123)
--FILE--
<?php
declare(strict_types=1);
$a = ['10', '2', '1'];
sort($a, SORT_NUMERIC);
echo implode(',', $a), "\n";
$d = ['10', '2', '1'];
rsort($d, SORT_NUMERIC);
echo implode(',', $d), "\n";
--EXPECT--
1,2,10
10,2,1
