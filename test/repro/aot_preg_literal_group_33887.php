<?php
declare(strict_types=1);

// #33887 — single literal / named literal capture under thin AOT.
$r1 = preg_match('/(x)/', 'x', $m1);
echo 'unnamed_r=', $r1, ' m0=', $m1[0] ?? '', ' m1=', $m1[1] ?? '', "\n";

$r2 = preg_match('/(?<a>x)/', 'x', $m2);
echo 'named_r=', $r2, ' a=', $m2['a'] ?? '', ' m1=', $m2[1] ?? '', "\n";

$r3 = preg_match('/(?P<b>foo)/', 'foobar', $m3);
echo 'p_r=', $r3, ' b=', $m3['b'] ?? '', ' m1=', $m3[1] ?? '', "\n";
