<?php

declare(strict_types=1);

// Compile-only (#8886): mixed int/enum haystacks must preserve literal ints for array_search strict.
enum E: int
{
    case A = 1;
}

var_export(array_search(1, [E::A, 1], true));
echo "\n";
var_export(array_search(1, [1, E::A], true));
echo "\n";
