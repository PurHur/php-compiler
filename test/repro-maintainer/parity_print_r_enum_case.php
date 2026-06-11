<?php

enum E: int
{
    case A = 1;
}

enum U
{
    case B;
}

echo print_r(E::A, true);
echo "---\n";
echo print_r(U::B, true);
