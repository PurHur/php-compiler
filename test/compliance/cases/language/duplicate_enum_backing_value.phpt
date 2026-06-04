--TEST--
Language: duplicate backed enum case values — Error like Zend (zend_enum.c, #5672)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
echo "before\n";
try {
    echo E::A->name, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo "after\n";
--EXPECT--
before
Duplicate value in enum E for cases A and B
after
