--TEST--
/ and % with zero divisor throw DivisionByZeroError (issue #5006, zend_operators.c)
--FILE--
<?php
try {
    $x = 5 % 0;
} catch (DivisionByZeroError $e) {
    echo "mod\n";
}
try {
    $x = 5 / 0;
} catch (DivisionByZeroError $e) {
    echo "div\n";
}
try {
    $x = 5.0 / 0.0;
} catch (DivisionByZeroError $e) {
    echo "fdiv\n";
}
?>
--EXPECT--
mod
div
fdiv
