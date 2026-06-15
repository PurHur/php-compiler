<?php

declare(strict_types=1);

enum Color
{
    case Red;
    case Green;
}

enum E: string
{
    case A = 'a';
    case B = 'b';
}

var_export(Color::cases());
echo "\n";
var_export(E::tryFrom('a'));
echo "\n";
var_export(E::from('a'));
echo "\n";
