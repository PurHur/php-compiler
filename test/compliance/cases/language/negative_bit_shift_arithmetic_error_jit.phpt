--TEST--
Language JIT: negative << / >> throws catchable ArithmeticError (zend_operators.c, #21912)
--FILE--
<?php
try {
    $x = 1 << -1;
    echo "result=$x\n";
} catch (ArithmeticError $e) {
    echo "caught ArithmeticError: ", $e->getMessage(), "\n";
}
echo "after\n";
try {
    $y = 8 >> -2;
    echo "result=$y\n";
} catch (ArithmeticError $e) {
    echo "caught ArithmeticError: ", $e->getMessage(), "\n";
}
echo "after2\n";
--EXPECT--
caught ArithmeticError: Bit shift by negative number
after
caught ArithmeticError: Bit shift by negative number
after2
