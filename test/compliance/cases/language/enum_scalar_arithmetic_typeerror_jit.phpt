--TEST--
Backed int enum + scalar arithmetic throws TypeError (JIT, #5790, zend_operators.c)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    var_export(E::A + 1);
    echo "no throw\n";
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    var_export(E::A * 2);
} catch (TypeError $e) {
    echo "mul: TypeError\n";
}
?>
--EXPECT--
TypeError:Unsupported operand types: E + int
mul: TypeError
