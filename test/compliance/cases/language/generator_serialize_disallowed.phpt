--TEST--
language Generator serialize()/unserialize() reject (issue #23044, Zend/zend_generators.c)
--FILE--
<?php
// Prove Closure deny still works; run before Generator paths.
$c = function () {};
try {
    serialize($c);
    echo "closure:no-throw\n";
} catch (Throwable $e) {
    echo 'closure:', get_class($e), ':', $e->getMessage(), "\n";
}

$g = (function () { yield 1; })();
try {
    serialize($g);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:9:"Generator":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
closure:Exception:Serialization of 'Closure' is not allowed
Exception:Serialization of 'Generator' is not allowed
Exception:Unserialization of 'Generator' is not allowed
