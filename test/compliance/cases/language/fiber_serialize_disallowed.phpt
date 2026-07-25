--TEST--
language Fiber serialize()/unserialize() reject (issue #23043, Zend/zend_fibers.c)
--FILE--
<?php
$f = new Fiber(fn() => 1);
try {
    serialize($f);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:5:"Fiber":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'Fiber' is not allowed
Exception:Unserialization of 'Fiber' is not allowed
