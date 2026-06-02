--TEST--
Language: enum case in class constant initializer (#4445)
--FILE--
<?php
enum E: int {
    case A = 1;
}

class C {
    public const X = E::A;
}

echo get_debug_type(C::X), "\n";
echo (C::X === E::A) ? "same\n" : "diff\n";
echo C::X->name, "\n";
echo C::X->value, "\n";
--EXPECT--
E
same
A
1

