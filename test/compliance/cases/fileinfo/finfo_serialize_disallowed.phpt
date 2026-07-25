--TEST--
fileinfo finfo serialize()/unserialize() reject (issue #23093, ext/fileinfo/fileinfo.stub.php)
--FILE--
<?php
$f = new finfo();
try {
    serialize($f);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:5:"finfo":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'finfo' is not allowed
Exception:Unserialization of 'finfo' is not allowed
