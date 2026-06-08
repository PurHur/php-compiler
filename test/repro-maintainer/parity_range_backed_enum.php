<?php

enum E: int
{
    case A = 1;
    case B = 3;
}

var_export(range(E::A, E::B));
echo "\n";
