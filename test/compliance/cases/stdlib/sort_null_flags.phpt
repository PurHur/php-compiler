--TEST--
stdlib sort()/rsort() null flags — SORT_REGULAR not TypeError (#18375, ext/standard/array.c)
--FILE--
<?php
$a = [3, 1, 2];
sort($a, null);
echo implode(',', $a), "\n";
$b = [3, 1, 2];
rsort($b, null);
echo implode(',', $b), "\n";
?>
--EXPECT--
1,2,3
3,2,1
