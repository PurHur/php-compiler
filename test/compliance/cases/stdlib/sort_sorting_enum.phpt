--TEST--
stdlib sort()/rsort() accept Sorting enum and SortDirection named param (#9947, ext/standard/array.c)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

$a = [3, 1, 2];
sort($a, Sorting::Ascending);
echo implode(',', $a), "\n";

$b = ['10', '2', '1'];
sort($b, Sorting::Ascending);
echo implode(',', $b), "\n";

$c = [3, 1, 2];
sort($c, direction: SortDirection::Ascending);
echo implode(',', $c), "\n";

$d = [3, 1, 2];
rsort($d, Sorting::Descending);
echo implode(',', $d), "\n";
?>
--EXPECT--
1,2,3
1,10,2
1,2,3
3,2,1
