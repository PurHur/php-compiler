--TEST--
Language: << and >> with float operands throw TypeError (zend_operators.c, #5008)
--FILE--
<?php
try {
    $x = 1 << 1.5;
} catch (TypeError $e) {
    echo 'int << float:', $e->getMessage(), "\n";
}
try {
    $x = 1.5 << 1;
} catch (TypeError $e) {
    echo 'float << int:', $e->getMessage(), "\n";
}
try {
    $x = 1 >> 1.5;
} catch (TypeError $e) {
    echo 'int >> float:', $e->getMessage(), "\n";
}
?>
--EXPECT--
int << float:Unsupported operand types: int << float
float << int:Unsupported operand types: float << int
int >> float:Unsupported operand types: int >> float
