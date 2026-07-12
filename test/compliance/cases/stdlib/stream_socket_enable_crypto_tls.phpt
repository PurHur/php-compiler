--TEST--
stdlib stream_socket_enable_crypto() — TLS client handshake (@group ssl, #4610)
--SKIPIF--
<?php
if (!extension_loaded('openssl')) {
    die('skip openssl not loaded');
}
if (!function_exists('stream_socket_enable_crypto')) {
    die('skip stream_socket_enable_crypto missing');
}
?>
--FILE--
<?php
declare(strict_types=1);

$ctx = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);
$fp = @stream_socket_client('tcp://example.com:443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
if (false === $fp) {
    echo "connect-skip\n";
    exit(0);
}
$ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
echo $ok ? "tls-ok\n" : "tls-fail\n";
?>
--EXPECT--
tls-ok
