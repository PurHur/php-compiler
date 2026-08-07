<?php
/**
 * Issue #27218 peers — thin AOT array_udiff_assoc / array_uintersect_assoc.
 * Expected: udiff_assoc=2,3 keys=b,c ; uintersect_assoc=1 keys=a
 */
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['a' => 1, 'b' => 20, 'd' => 4];
$r = array_udiff_assoc($a, $b, fn ($x, $y) => $x <=> $y);
echo 'udiff_assoc=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
$r = array_uintersect_assoc($a, $b, fn ($x, $y) => $x <=> $y);
echo 'uintersect_assoc=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
