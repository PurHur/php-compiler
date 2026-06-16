<?php
declare(strict_types=1);
// Compile-only (#8947): array_udiff() with inline enum-case arrays + closure comparator lowers for AOT.
enum E: int { case A = 1; case B = 2; }

$cmp = static fn ($x, $y) => $x <=> $y;
$r = array_udiff([E::A, E::B], [E::B], $cmp);
var_export($r[0] === E::A);
