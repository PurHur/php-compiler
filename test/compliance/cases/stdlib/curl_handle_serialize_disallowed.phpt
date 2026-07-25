--TEST--
stdlib CurlHandle serialize()/unserialize() reject (issue #23074, ext/curl/curl.stub.php)
--FILE--
<?php
$ch = curl_init();
try {
    serialize($ch);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:10:"CurlHandle":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'CurlHandle' is not allowed
Exception:Unserialization of 'CurlHandle' is not allowed
