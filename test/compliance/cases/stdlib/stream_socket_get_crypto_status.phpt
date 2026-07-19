--TEST--
stdlib stream_socket_get_crypto_status() — PHP 8.6 TLS crypto status (#21021)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.6');
if (!PHPCompiler\CompilerVersion::supportsStreamSocketGetCryptoStatus()) {
    die('skip stream_socket_get_crypto_status requires PHP_COMPILER_PROFILE=8.6');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.6
--FILE--
<?php
echo function_exists('stream_socket_get_crypto_status') ? "exists-ok\n" : "exists-fail\n";

$mem = fopen('php://memory', 'r+');
$status = @stream_socket_get_crypto_status($mem);
echo 0 === $status ? "mem-none-ok\n" : "mem-none-fail\n";

try {
    stream_socket_get_crypto_status('not-a-stream');
    echo "type-fail\n";
} catch (TypeError $e) {
    echo "type-ok\n";
}

$ctx = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);
$s = @stream_socket_client('tcp://example.com:443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
if (false === $s) {
    echo "net-skip\n";
    exit(0);
}
$enabled = @stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
echo $enabled ? "tls-ok\n" : "tls-fail\n";
$st = stream_socket_get_crypto_status($s);
echo is_int($st) && 0 === $st ? "status-ok\n" : "status-fail\n";
fclose($s);
?>
--EXPECTF--
exists-ok
mem-none-ok
type-ok
%a
