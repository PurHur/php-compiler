<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

var_export(in_array(E::A, [1, 2], true));
echo "\n";
