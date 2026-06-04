--TEST--
Language: duplicate backed enum case values — compile-time fatal (zend_enum.c, #5710)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
echo "run\n";
--EXPECT_EXIT--
255
