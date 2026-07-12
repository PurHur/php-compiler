<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

$a = [E::A, E::B];
var_export(array_sum($a));
echo "\n";
var_export(array_product($a));
echo "\n";
