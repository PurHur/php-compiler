--TEST--
stdlib sort()/rsort() SORT_STRING on integer elements (#11288, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = [3, 20, 5];
sort($a, SORT_STRING);
echo implode(',', $a), "\n";
$b = [3, 20, 5];
rsort($b, SORT_STRING);
echo implode(',', $b), "\n";
--EXPECT--
20,3,5
5,3,20
