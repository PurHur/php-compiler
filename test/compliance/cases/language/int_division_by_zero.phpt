--TEST--
Integer division by zero still throws DivisionByZeroError
--FILE--
<?php
try {
    var_dump(1 / 0);
} catch (DivisionByZeroError $e) {
    echo "ok\n";
}
?>
--EXPECT--
ok
