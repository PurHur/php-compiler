<?php
// Compile-only (#5553): array_pad()/array_chunk() on enum case arrays.
enum E: int
{
    case A = 1;
    case B = 2;
}
$a = [E::A, E::B];
$p = array_pad($a, 4, E::A);
$c = array_chunk($a, 1);
echo ($p[0] instanceof E ? '1' : '0'), ($p[3] instanceof E ? '1' : '0'), "\n";
echo ($c[0][0] instanceof E ? '1' : '0'), ($c[1][0] instanceof E ? '1' : '0'), "\n";
