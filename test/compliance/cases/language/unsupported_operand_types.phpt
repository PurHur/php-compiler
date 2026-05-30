--TEST--
Unsupported operand types throw TypeError (issue #3695, Zend zend_operators.c)
--FILE--
<?php
try {
    var_export([1, 2] * 3);
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    [1] - [2];
} catch (TypeError $e) {
    echo 'TypeError:', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError:Unsupported operand types: array * int
TypeError:Unsupported operand types: array - array
