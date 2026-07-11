--TEST--
stdlib stream_socket_enable_crypto() — registration + TypeError/ValueError parity (#4610, ext/standard/streamsfuncs.c)
--FILE--
<?php
declare(strict_types=1);

echo function_exists('stream_socket_enable_crypto') ? "exists\n" : "missing\n";

try {
    stream_socket_enable_crypto('not-a-stream', true);
    echo "type-fail\n";
} catch (TypeError $e) {
    echo "type-ok\n";
}

$fp = fopen('php://memory', 'r+');
try {
    stream_socket_enable_crypto($fp, true);
    echo "value-fail\n";
} catch (ValueError $e) {
    echo str_contains($e->getMessage(), 'crypto_method') ? "value-ok\n" : "value-msg-fail\n";
}

$disabled = stream_socket_enable_crypto($fp, false);
echo $disabled ? "disable-ok\n" : "disable-fail\n";
?>
--EXPECT--
exists
type-ok
value-ok
disable-ok
