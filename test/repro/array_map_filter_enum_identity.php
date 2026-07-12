<?php
enum E: int { case A = 1; case B = 2; }

var_export(array_map(fn($x) => $x, [E::A, E::B]));
echo "\n";
var_export(array_filter([E::A, E::B], fn($x) => $x === E::A));
echo "\n";
