--TEST--
stdlib ksort/krsort/uksort/array_multisort SORT_STRING on int keys (#10966, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$a = [10 => 'b', 2 => 'a'];
ksort($a, SORT_STRING);
echo implode(',', array_keys($a)), "\n";

krsort($a, SORT_STRING);
echo implode(',', array_keys($a)), "\n";

$b = [10 => 'b', 2 => 'a'];
uksort($b, 'strcmp');
echo implode(',', array_keys($b)), "\n";

$k = [10, 2];
$v = ['b', 'a'];
array_multisort($k, SORT_STRING, $v);
echo implode(',', $k), "\n";
--EXPECT--
10,2
2,10
10,2
10,2
