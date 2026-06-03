--TEST--
stdlib intdiv() JIT — DivisionByZeroError message matches Zend (#5038)
--FILE--
<?php
try {
    intdiv(1, 0);
} catch (DivisionByZeroError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Division by zero
