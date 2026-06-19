<?php
enum E: int {
    case A = 1;
    case B = 2;
}

echo E::A::class, "\n";
echo (E::B)::class, "\n";

$a = E::A;
echo $a::class, "\n";

enum U { case C; }
echo U::C::class, "\n";
