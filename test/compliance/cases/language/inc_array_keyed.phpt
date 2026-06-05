--TEST--
Language: increment keyed array — catchable TypeError (zend_operators.c, #6398)
--FILE--
<?php
try {
    $a = ['a' => 1];
    $a++;
} catch (TypeError $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
try {
    $b = ['x' => 2];
    ++$b;
} catch (TypeError $e) {
    echo 'caught:', $e->getMessage(), "\n";
}
?>
--EXPECT--
caught:Cannot increment array
caught:Cannot increment array
