--TEST--
stdlib array_udiff_uassoc/uintersect_uassoc thin AOT dual comparators (#27243, ext/standard/array.c)
--FILE--
<?php
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
--EXPECT--
udiff=2 keys=b
uintersect=2 keys=b
