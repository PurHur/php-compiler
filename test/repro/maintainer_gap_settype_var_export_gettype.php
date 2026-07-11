<?php

declare(strict_types=1);

enum E: int
{
    case A = 42;
}

$x = E::A;
@settype($x, 'int');
var_export($x);
echo "\n";
var_export(gettype($x));
echo "\n";
