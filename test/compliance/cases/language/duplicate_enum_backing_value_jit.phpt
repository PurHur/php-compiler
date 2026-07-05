--TEST--
Language: duplicate backed enum values — JIT definition compiles (#9255, #8687, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
echo "run\n";
--EXPECT--
run
