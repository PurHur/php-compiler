--TEST--
stdlib socket_import_stream(STDIN) — returns Socket for STDIO (#18509, ext/sockets/sockets.c)
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
is_socket=yes
warning=none
