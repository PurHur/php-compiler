<?php

declare(strict_types=1);

/**
 * Maintainer repro: unlink()/rename() on php:// URIs — wrapper warning text (#18404).
 */
$warnings = [];
set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

$unlinkOk = unlink('php://memory');
$renameOk = rename('php://memory', 'php://temp');

echo 'unlink=', var_export($unlinkOk, true), "\n";
echo 'rename=', var_export($renameOk, true), "\n";
echo 'warnings=', implode('|', $warnings), "\n";
