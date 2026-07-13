<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
});

$result = @socket_import_stream(STDIN);

echo 'is_socket=', is_object($result) && $result instanceof Socket ? 'yes' : 'no', "\n";
echo 'warning=', $warnings[0] ?? 'none', "\n";
