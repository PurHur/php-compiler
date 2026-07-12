<?php

declare(strict_types=1);

/**
 * Maintainer repro: scandir()/opendir() on php://filter — Zend wrapper diagnostics (#18418).
 */
$path = 'php://filter/read=string.rot13/resource=data://text/plain,test';

$warnings = [];
set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

$scandirOk = scandir($path);
$opendirOk = opendir($path);

echo 'scandir=', var_export($scandirOk, true), "\n";
echo 'opendir=', var_export($opendirOk, true), "\n";
echo 'warnings=', implode('|', $warnings), "\n";
