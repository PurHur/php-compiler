--TEST--
Language: duplicate backed enum values — Error at first use (#5773, #8687, #8876, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
echo "run\n";
--EXPECT_EXIT--
255
