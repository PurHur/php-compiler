<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

$a = [E::A, E::B];
var_export(current($a));
echo PHP_EOL;
next($a);
var_export(current($a));
echo PHP_EOL;
var_export(end($a));
echo PHP_EOL;
