<?php
/**
 * Issue #27228 — thin AOT array_intersect_ukey with arrow key comparator must match Zend.
 * Expected: 1,3 keys=a,c
 */
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['a' => 4, 'c' => 9];
$r = array_intersect_ukey($a, $b, fn ($k1, $k2) => $k1 <=> $k2);
echo implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
