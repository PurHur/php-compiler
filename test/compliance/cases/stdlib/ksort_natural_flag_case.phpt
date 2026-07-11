--TEST--
stdlib ksort() SORT_NATURAL|SORT_FLAG_CASE inline expression (#9278, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$a = ['A1', 'a2', 'a10'];
ksort($a, SORT_NATURAL | SORT_FLAG_CASE);
echo implode(',', $a), "\n";
$f = SORT_NATURAL | SORT_FLAG_CASE;
$b = ['A1', 'a2', 'a10'];
ksort($b, $f);
echo implode(',', $b), "\n";
--EXPECT--
A1,a2,a10
A1,a2,a10
