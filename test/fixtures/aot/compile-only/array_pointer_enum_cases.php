<?php
// Compile-only (#8749): array pointer builtins on enum case arrays must not reuse hoisted enum fetches.
declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

$a = [E::A, E::B];
var_export(current($a));
next($a);
var_export(current($a));
var_export(end($a));
