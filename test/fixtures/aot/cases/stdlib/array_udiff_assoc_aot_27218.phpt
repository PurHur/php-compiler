--TEST--
stdlib array_udiff_assoc/uintersect_assoc thin AOT peers (#27218, ext/standard/array.c)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2, 'c' => 3];
$b = ['a' => 1, 'b' => 20, 'd' => 4];
$r = array_udiff_assoc($a, $b, fn ($x, $y) => $x <=> $y);
echo 'udiff_assoc=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
$r = array_uintersect_assoc($a, $b, fn ($x, $y) => $x <=> $y);
echo 'uintersect_assoc=', implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
--EXPECT--
udiff_assoc=2,3 keys=b,c
uintersect_assoc=1 keys=a
