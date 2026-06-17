--TEST--
Language: duplicate backed enum values throw Error on first use (zend_enum.c, #9193)
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

enum F: int {
    case A = 1;
    case B = 2;
}
echo F::A->name, "\n";
--EXPECT--
Error: Duplicate value in enum E for cases A and B
A
