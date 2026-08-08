--TEST--
stdlib Sorting phantom absent; sort()/rsort() int flags only (#28930, re-#9947)
--SKIPIF--
<?php die('skip — compiler VM/JIT compliance via VMTest/JITTest, not Zend CLI'); ?>
--FILE--
<?php
declare(strict_types=1);

var_export(enum_exists('Sorting', false));
echo "\n";
$a = [3, 1, 2];
sort($a, SORT_REGULAR);
echo implode(',', $a), "\n";
$d = [3, 1, 2];
rsort($d, SORT_REGULAR);
echo implode(',', $d), "\n";
?>
--EXPECT--
false
1,2,3
3,2,1
