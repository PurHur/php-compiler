--TEST--
language: include with enum path operand throws catchable Error (#5987, Zend/zend_execute.c)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    include E::A;
    echo "include\n";
} catch (\Error $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}
--EXPECT--
caught: Object of class E could not be converted to string
