--TEST--
Language: backed enum E::cases() static call and array spread (#5507)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 2;
}
echo count(E::cases());
echo "\n";
echo count([...E::cases()]);
echo "\n";
--EXPECT--
2
2
