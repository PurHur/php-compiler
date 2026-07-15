--TEST--
Language: clone null operand throws catchable Error (#19097, Zend/zend_clones.c)
--FILE--
<?php
try {
    clone null;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$maybeNull = null;
try {
    clone $maybeNull;
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: __clone method called on non-object
Error: __clone method called on non-object
