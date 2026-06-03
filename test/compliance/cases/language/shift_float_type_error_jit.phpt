--TEST--
Language: << and >> with float operands throw TypeError (JIT, #5008)
--FILE--
<?php
try {
    var_dump(1 << 1.5);
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError:Unsupported operand types: int << float
