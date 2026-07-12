--TEST--
stdlib socket_import_stream(STDIN) — STDIO warning text (#18389, ext/sockets/sockets.c)
--FILE--
<?php

$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
});

$result = @socket_import_stream(STDIN);

if (false !== $result) {
    echo 'bad_result', "\n";
} else {
    echo $warnings[0] ?? 'missing', "\n";
}
--EXPECT--
socket_import_stream(): Cannot represent a stream of type STDIO as a Socket Descriptor
