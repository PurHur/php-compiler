--TEST--
stdlib sort()/rsort() SORT_NUMERIC on string elements (#4076, #9123, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = ['10', '2', '1'];
sort($a, SORT_NUMERIC);
echo implode(',', $a), "\n";
$b = ['10', '2', '1'];
asort($b, SORT_NUMERIC);
echo implode(',', $b), "\n";
$c = ['10', '2', '1'];
arsort($c, SORT_NUMERIC);
echo implode(',', $c), "\n";
$d = ['10', '2', '1'];
rsort($d, SORT_NUMERIC);
echo implode(',', $d), "\n";
--EXPECT--
1,2,10
1,2,10
10,2,1
10,2,1
