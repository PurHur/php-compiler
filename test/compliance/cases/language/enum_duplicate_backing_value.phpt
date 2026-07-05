--TEST--
Language: duplicate backed enum values — definition compiles (#8687, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
echo "run\n";
--EXPECT--
run
