<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

var_export(array_is_list([E::A, E::B]));
echo "\n";
var_export(array_first([E::A, E::B]));
echo "\n";
var_export(array_last([E::A, E::B]));
echo "\n";

