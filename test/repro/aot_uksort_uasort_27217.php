<?php
/**
 * Issue #27217 — thin AOT uksort/uasort with arrow comparators must match Zend
 * (no abort). Expected:
 *   a,b,c
 *   1,2,3
 */
$a = ['b' => 1, 'a' => 2, 'c' => 0];
uksort($a, fn ($x, $y) => $x <=> $y);
echo implode(',', array_keys($a)), "\n";
$b = ['b' => 2, 'a' => 1, 'c' => 3];
uasort($b, fn ($x, $y) => $x <=> $y);
echo implode(',', $b), "\n";
