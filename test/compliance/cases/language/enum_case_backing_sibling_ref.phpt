--TEST--
Language: backed enum case backing expression references sibling case (#6842, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = self::A->value + 1;
    case C = self::B->value * 2;
}
echo E::B->value, "\n", E::C->value, "\n";
--EXPECT--
2
4
