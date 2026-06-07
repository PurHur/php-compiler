--TEST--
Stdlib: class_implements() on enum case object — UnitEnum/BackedEnum (#5651)
--FILE--
<?php
enum E: int {
    case A = 1;
}
enum U {
    case B;
}
$byCase = class_implements(E::A);
$byClass = class_implements(E::class);
$unitCase = class_implements(U::B);
$unitClass = class_implements(U::class);
echo isset($byCase['UnitEnum']) ? '1' : '0';
echo isset($byCase['BackedEnum']) ? '1' : '0';
echo isset($byClass['UnitEnum']) ? '1' : '0';
echo isset($byClass['BackedEnum']) ? '1' : '0';
echo isset($unitCase['UnitEnum']) ? '1' : '0';
echo isset($unitCase['BackedEnum']) ? '0' : '1';
echo isset($unitClass['UnitEnum']) ? '1' : '0';
echo isset($unitClass['BackedEnum']) ? '0' : '1';
echo "\n";
--EXPECT--
11111111
