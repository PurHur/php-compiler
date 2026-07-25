--TEST--
language WeakMap serialize()/unserialize() reject (issue #23062, Zend/zend_weakrefs.c)
--FILE--
<?php
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = 1;
try {
    serialize($wm);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:7:"WeakMap":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$empty = new WeakMap();
try {
    serialize($empty);
    echo "empty:no-throw\n";
} catch (Throwable $e) {
    echo 'empty:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'WeakMap' is not allowed
Exception:Unserialization of 'WeakMap' is not allowed
empty:Exception:Serialization of 'WeakMap' is not allowed
