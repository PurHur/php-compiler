--TEST--
Power operator (**) with numeric-string operands (zend_operators.c)
--FILE--
<?php
echo 2 ** "3", "\n";
echo "2" ** 3, "\n";
try {
    2 ** "abc";
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
try {
    "abc" ** 2;
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
--EXPECT--
8
8
TypeError:Unsupported operand types: int ** string
TypeError:Unsupported operand types: string ** int
