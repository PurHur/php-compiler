<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

enum U
{
    case A;
}

var_export(get_class_vars(E::class));
echo "\n---\n";
var_export(get_class_vars(U::class));
echo "\n";
