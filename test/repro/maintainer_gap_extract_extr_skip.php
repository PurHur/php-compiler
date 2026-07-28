<?php
// Compile-allocated locals that appear later must NOT count as existing for EXTR_SKIP.
// Zend: 2 / 1 / 3 / 4
// VM:   0 / 1 / warnings for $b and $c
$a = 1;
$arr = ['a' => 2, 'b' => 3, 'c' => 4];
$n = extract($arr, EXTR_SKIP);
echo $n, "\n";
echo $a, "\n";
echo $b, "\n";
echo $c, "\n";
