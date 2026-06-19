--TEST--
Language: enum case ::class pseudo-constant — direct, parenthesized, variable (#9811)
--FILE--
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
--EXPECT--
E
E
E
U
