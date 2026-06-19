--TEST--
Language: duplicate backed enum values — compile-time fatal (#9677, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
echo "run\n";
--EXPECT_EXIT--
255
