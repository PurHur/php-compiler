<?php
/** Maintainer repro for #8867 — usort() must preserve enum case objects (ext/standard/array.c). */
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }
$a = [E::B, E::A];
usort($a, fn($x, $y) => $x <=> $y);
var_export($a);
echo "\n";
