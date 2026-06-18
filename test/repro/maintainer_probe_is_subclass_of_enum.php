<?php

enum E: int {
    case A = 1;
}

var_export(is_subclass_of('E', UnitEnum::class));
echo "\n";
var_export(is_subclass_of(E::A, UnitEnum::class));
echo "\n";
var_export(is_a(E::A, UnitEnum::class, true));
echo "\n";
