--TEST--
stdlib serialize()/unserialize() reject Closure (issue #9042, Zend/zend_closures.c)
--FILE--
<?php
$x = function () {};
try {
    serialize($x);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:7:"Closure":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'Closure' is not allowed
Exception:Unserialization of 'Closure' is not allowed
