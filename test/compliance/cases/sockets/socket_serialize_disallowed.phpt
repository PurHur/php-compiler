--TEST--
sockets Socket/AddressInfo serialize()/unserialize() reject (issue #23094, ext/sockets/sockets.stub.php)
--FILE--
<?php
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$ai = socket_addrinfo_lookup('127.0.0.1', '80', ['ai_socktype' => SOCK_STREAM]);
$o = $ai[0];
try {
    serialize($sock);
    echo "Socket serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:6:"Socket":0:{}');
    echo "Socket unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    serialize($o);
    echo "AddressInfo serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    unserialize('O:11:"AddressInfo":0:{}');
    echo "AddressInfo unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
Exception:Serialization of 'Socket' is not allowed
Exception:Unserialization of 'Socket' is not allowed
Exception:Serialization of 'AddressInfo' is not allowed
Exception:Unserialization of 'AddressInfo' is not allowed
