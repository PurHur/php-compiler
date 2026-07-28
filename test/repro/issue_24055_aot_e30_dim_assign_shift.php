<?php
// Issue #24055 — AOT: dim-assign expression value + nested [$a] packing after array_shift
// (Zend/zend_execute.c INIT_ARRAY left-to-right; HashTableWriteLlvm setValueBoxAtIndex).
$a = [1, 2, 3];
$r0 = $a[0] = 99;
echo "r0=", $r0, " a0=", $a[0], "\n";

$a = [1, 2, 3];
$tmp = [$a[0] = 99, array_shift($a), $a];
echo '[', $tmp[0], ',', $tmp[1], ',[', $tmp[2][0], ',', $tmp[2][1], ']]', "\n";
