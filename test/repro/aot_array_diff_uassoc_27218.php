<?php
/**
 * Issue #27218 — thin AOT array_diff_uassoc / array_intersect_uassoc.
 * Expected (match Zend/VM; avoid ksort under HELPER_RUNTIME_O=0):
 *   diff_uassoc=2,3 keys=b,c
 *   intersect_uassoc=1 keys=a
 */
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['a' => 1, 'b' => 20, 'd' => 4];
$r = array_diff_uassoc($a, $b, fn ($x, $y) => $x <=> $y);
echo 'diff_uassoc=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
$r = array_intersect_uassoc($a, $b, fn ($x, $y) => $x <=> $y);
echo 'intersect_uassoc=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
