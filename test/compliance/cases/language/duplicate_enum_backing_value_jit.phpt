--TEST--
Language: duplicate backed enum values — JIT Error at first use (#9255, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
try {
    echo E::A->name, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Duplicate value in enum E for cases A and B
