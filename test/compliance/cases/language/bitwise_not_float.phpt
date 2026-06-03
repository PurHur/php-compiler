--TEST--
Language: unary ~ on float throws TypeError (zend_operators.c, #5007)
--FILE--
<?php
$x = 1.5;
try {
    ~$x;
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Unsupported operand types: float
