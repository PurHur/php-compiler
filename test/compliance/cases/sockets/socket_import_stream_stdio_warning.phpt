--TEST--
stdlib socket_import_stream(STDIN) — STDIO import rejected with Warning (#18553, ext/sockets/sockets.c)
--FILE--
<?php

$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
});

$result = @socket_import_stream(STDIN);

echo 'is_socket=', is_object($result) && $result instanceof Socket ? 'yes' : 'no', "\n";
echo 'warning=', $warnings[0] ?? 'none', "\n";
--EXPECT--
is_socket=no
warning=socket_import_stream(): Cannot represent a stream of type STDIO as a Socket Descriptor
