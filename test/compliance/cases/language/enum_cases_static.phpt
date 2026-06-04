--TEST--
Language: UnitEnum::cases() static dispatch (#5462)
--FILE--
<?php
enum E {
    case A;
    case B;
}
echo count(E::cases());
echo "\n";
echo E::cases()[0]->name;
echo "\n";
--EXPECT--
2
A
