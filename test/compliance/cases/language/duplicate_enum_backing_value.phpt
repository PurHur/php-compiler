--TEST--
Language: duplicate backed enum values — Error at first use (#5773, #8687, #8876, zend_enum.c)
--FILE--
<?php
enum E: int {
    case A = 1;
    case B = 1;
}
try {
    echo E::A->name, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Duplicate value in enum E for cases A and B
