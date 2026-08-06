<?php
/**
 * Issue #27243 — thin AOT array_udiff_uassoc / array_uintersect_uassoc with dual arrow comparators.
 * Expected: udiff=2 keys=b ; uintersect=2 keys=b
 * (json_encode of dynamic HTs under thin AOT is a separate gap; match #27228 print shape.)
 */
$a = ['a' => 1, 'b' => 2];
$b = ['a' => 1, 'b' => 3];
$r = array_udiff_uassoc(
    $a,
    $b,
    fn ($x, $y) => $x <=> $y,
    fn ($x, $y) => strcmp((string) $x, (string) $y)
);
echo 'udiff=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['a' => 10, 'b' => 2, 'd' => 4];
$r = array_uintersect_uassoc(
    $a,
    $b,
    fn ($x, $y) => $x <=> $y,
    fn ($x, $y) => strcmp((string) $x, (string) $y)
);
echo 'uintersect=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
