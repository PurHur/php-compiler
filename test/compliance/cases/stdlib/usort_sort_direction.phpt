--TEST--
stdlib usort/uasort/uksort accept SortDirection named param (#17429, ext/standard/array.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$a = [3, 1, 2];
usort($a, 'strcmp', direction: SortDirection::Ascending);
echo implode(',', $a), "\n";

$b = [3, 1, 2];
usort($b, 'strcmp', direction: SortDirection::Descending);
echo implode(',', $b), "\n";

$c = ['b' => 2, 'a' => 1, 'c' => 3];
uasort($c, 'strcmp', direction: SortDirection::Ascending);
echo implode(',', array_keys($c)), "\n";

$d = ['b' => 2, 'a' => 1, 'c' => 3];
uksort($d, 'strcmp', direction: SortDirection::Descending);
echo implode(',', array_keys($d)), "\n";
?>
--EXPECT--
1,2,3
3,2,1
a,b,c
c,b,a
