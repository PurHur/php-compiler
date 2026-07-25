--TEST--
language WeakReference serialize()/unserialize() reject (issue #23063, Zend/zend_weakrefs.c)
--FILE--
<?php
$o = new stdClass();
$w = WeakReference::create($o);
try {
    $payload = serialize($w);
    echo "serialize:no-throw:", $payload, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
    if (false !== strpos($e->getMessage(), '__weak_target')) {
        echo "leak:message-contains-weak-target\n";
    }
}

try {
    unserialize('O:13:"WeakReference":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:13:"WeakReference":1:{s:13:"__weak_target";O:8:"stdClass":0:{}}');
    echo "unserialize-leak:no-throw\n";
} catch (Throwable $e) {
    echo 'leak:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'WeakReference' is not allowed
Exception:Unserialization of 'WeakReference' is not allowed
leak:Exception:Unserialization of 'WeakReference' is not allowed
