--TEST--
Language: duplicate backed enum values — compile-time fatal (#5773, #8687, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Duplicate value in enum E for cases A and B in %s on line %d
