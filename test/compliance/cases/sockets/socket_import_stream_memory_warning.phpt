--TEST--
stdlib socket_import_stream() MEMORY stream warning text (#17847, ext/sockets/sockets.c)
--FILE--
<?php

$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
});

$stream = fopen('php://memory', 'r+');
@socket_import_stream($stream);

echo $warnings[0] ?? 'missing', "\n";
--EXPECT--
socket_import_stream(): Cannot represent a stream of type MEMORY as a Socket Descriptor
