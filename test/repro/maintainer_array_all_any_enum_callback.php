<?php
declare(strict_types=1);

enum E: int { case A = 1; case B = 2; }

var_export(array_all([E::A, E::B], fn ($v) => $v instanceof E));
echo "\n";
var_export(array_any([E::A, E::B], fn ($v) => $v instanceof E));
echo "\n";
