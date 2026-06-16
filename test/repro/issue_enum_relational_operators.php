<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

enum S: string
{
    case X = 'a';
    case Y = 'b';
}

var_dump(E::A < E::B);
var_dump(E::A <=> E::B);
var_dump(S::X < S::Y);
var_dump(S::X <=> S::Y);
