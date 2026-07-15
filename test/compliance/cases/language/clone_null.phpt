--TEST--
Language: clone null operand throws catchable Error (#19097, Zend/zend_clones.c)
--FILE--
<?php
try {
    $c = clone null;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$maybeNull = null;
try {
    $c = clone $maybeNull;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: __clone method called on non-object
Error: __clone method called on non-object
