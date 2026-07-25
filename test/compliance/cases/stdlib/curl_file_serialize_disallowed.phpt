--TEST--
stdlib CURLFile serialize()/unserialize() reject (issue #23064, ext/curl/curl_file.stub.php @not-serializable)
--FILE--
<?php
$f = new CURLFile('/tmp/x');
try {
    serialize($f);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:8:"CURLFile":3:{s:4:"name";s:6:"/tmp/x";s:4:"mime";s:0:"";s:8:"postname";s:0:"";}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'CURLFile' is not allowed
Exception:Unserialization of 'CURLFile' is not allowed
