<?php

declare(strict_types=1);

enum E: int {
    case A = 1;
}

var_export(is_a(E::A, E::class, true));
echo "\n";
var_export(is_a(E::A, 'BackedEnum', true));
echo "\n";
var_export(is_subclass_of(E::A, E::class, true));
echo "\n";
