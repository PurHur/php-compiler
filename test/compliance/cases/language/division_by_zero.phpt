--TEST--
/ and % with zero divisor throw DivisionByZeroError (issue #5006, zend_operators.c)
--FILE--
<?php
foreach (['5 % 0', '5 / 0'] as $expr) {
    try {
        eval('return ' . $expr . ';');
        echo $expr, " => ok\n";
    } catch (DivisionByZeroError $e) {
        echo $expr, " => ", get_class($e), "\n";
    }
}
try {
    var_dump(5.0 / 0.0);
} catch (DivisionByZeroError $e) {
    echo "5.0 / 0.0 => DivisionByZeroError\n";
}
?>
--EXPECT--
5 % 0 => DivisionByZeroError
5 / 0 => DivisionByZeroError
5.0 / 0.0 => DivisionByZeroError
