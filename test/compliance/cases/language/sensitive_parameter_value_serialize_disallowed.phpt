--TEST--
language SensitiveParameterValue serialize()/unserialize() reject (issue #23086, Zend/zend_exceptions.c)
--FILE--
<?php
$s = new SensitiveParameterValue('secret');
try {
    $payload = serialize($s);
    echo "serialize:no-throw:", $payload, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
    if (false !== strpos($e->getMessage(), 'secret')) {
        echo "leak:message-contains-secret\n";
    }
}

try {
    unserialize('O:23:"SensitiveParameterValue":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:23:"SensitiveParameterValue":1:{s:5:"value";s:6:"secret";}');
    echo "unserialize-leak:no-throw\n";
} catch (Throwable $e) {
    echo 'leak:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'SensitiveParameterValue' is not allowed
Exception:Unserialization of 'SensitiveParameterValue' is not allowed
leak:Exception:Unserialization of 'SensitiveParameterValue' is not allowed
