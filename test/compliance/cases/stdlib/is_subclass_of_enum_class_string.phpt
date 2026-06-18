--TEST--
Stdlib: is_subclass_of() class-string on enum vs UnitEnum (#9152)
--FILE--
<?php
enum E: int {
    case A = 1;
}
echo is_subclass_of('E', UnitEnum::class) ? '1' : '0';
echo is_subclass_of(E::A, UnitEnum::class) ? '1' : '0';
echo is_a(E::A, UnitEnum::class, true) ? '1' : '0';
echo "\n";
--EXPECT--
111
