--TEST--
stdlib array_multisort() inline first array + assign-in-call companion (#15151, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
array_multisort([3, 1, 2], $labels = ['c', 'a', 'b']);
echo json_encode($labels), "\n";
$a = [3, 1, 2];
$b = ['c', 'a', 'b'];
array_multisort($a, $b);
echo json_encode($b), "\n";
--EXPECT--
["c","a","b"]
["a","b","c"]
