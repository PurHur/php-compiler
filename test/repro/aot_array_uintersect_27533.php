<?php
/**
 * Issue #27533 — thin AOT array_uintersect with arrow value comparator must match Zend.
 * Expected: keys=b,c vals=2,3
 */
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['b' => 2, 'c' => 3, 'd' => 4];
$r = array_uintersect($a, $b, fn ($x, $y) => $x <=> $y);
ksort($r);
echo 'keys=', implode(',', array_keys($r)), ' vals=', implode(',', array_values($r)), "\n";
