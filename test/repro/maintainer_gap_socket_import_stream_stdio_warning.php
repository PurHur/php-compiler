<?php

declare(strict_types=1);

$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;

    return true;
});

$result = @socket_import_stream(STDIN);

echo 'result=', var_export($result, true), "\n";
echo 'warning=', $warnings[0] ?? 'missing', "\n";
