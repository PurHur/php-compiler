--TEST--
Language: enum case ++/-- throws TypeError with Zend message (zend_operators.c, #5525)
--FILE--
<?php
enum E { case A; }
enum I: int { case B = 1; }
$x = E::A;
$y = I::B;
try {
    $x++;
} catch (TypeError $e) {
    echo 'unit:', $e->getMessage(), "\n";
}
try {
    --$x;
} catch (TypeError $e) {
    echo 'unit-dec:', $e->getMessage(), "\n";
}
try {
    ++$y;
} catch (TypeError $e) {
    echo 'int:', $e->getMessage(), "\n";
}
try {
    $y--;
} catch (TypeError $e) {
    echo 'int-dec:', $e->getMessage(), "\n";
}
?>
--EXPECT--
unit:Cannot increment E
unit-dec:Cannot decrement E
int:Cannot increment I
int-dec:Cannot decrement I
