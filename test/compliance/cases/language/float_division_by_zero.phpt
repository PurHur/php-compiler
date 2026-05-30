--TEST--
Float division by zero throws DivisionByZeroError (Zend div_function parity)
--FILE--
<?php
try {
    var_dump(1.0 / 0.0);
} catch (DivisionByZeroError $e) {
    echo "ok\n";
}
?>
--EXPECT--
ok
