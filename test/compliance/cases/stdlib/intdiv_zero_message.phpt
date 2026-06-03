--TEST--
stdlib intdiv() — DivisionByZeroError message matches Zend (ext/standard/math.c, #5038)
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
