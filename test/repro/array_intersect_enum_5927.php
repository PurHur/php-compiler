<?php

enum E: string
{
    case A = 'a';
    case B = 'b';
}

var_dump(array_intersect([E::A, E::B], [E::A]));
