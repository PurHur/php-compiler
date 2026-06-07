--TEST--
Stdlib: is_subclass_of() on enum case object — UnitEnum/BackedEnum (#5642)
--FILE--
<?php
enum E: int {
    case A = 1;
}
enum U {
    case X;
}
echo is_subclass_of(E::A, 'BackedEnum') ? '1' : '0';
echo is_subclass_of(E::A, 'UnitEnum') ? '1' : '0';
echo is_subclass_of(U::X, 'UnitEnum') ? '1' : '0';
echo is_subclass_of(U::X, 'BackedEnum') ? '1' : '0';
echo is_subclass_of(E::A, 'E') ? '1' : '0';
echo is_subclass_of(E::A, 'BackedEnum', false) ? '1' : '0';
echo "\n";
--EXPECT--
111001
