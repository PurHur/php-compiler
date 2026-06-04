--TEST--
Language: array ++/-- must throw catchable TypeError (zend_operators.c, #5483)
--FILE--
<?php
try {
    $a = [];
    ++$a;
} catch (TypeError $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
try {
    $b = [];
    --$b;
} catch (TypeError $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
?>
--EXPECT--
caught:Cannot increment array
caught:Cannot decrement array
