--TEST--
FTP\Connection serialize()/unserialize()/new reject (issue #23134, ext/ftp/ftp.stub.php)
--FILE--
<?php
try {
    new FTP\Connection();
    echo "new:no-throw\n";
} catch (Throwable $e) {
    echo 'new:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:14:"FTP\Connection":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo 'unserialize:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
new:Error:Cannot directly construct FTP\Connection, use ftp_connect() or ftp_ssl_connect() instead
unserialize:Exception:Unserialization of 'FTP\Connection' is not allowed
