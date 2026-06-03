--TEST--
Language: unary ~ on float throws TypeError (JIT, #5007)
--FILE--
<?php
$x = 1.5;
try {
    ~$x;
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError:Unsupported operand types: float
