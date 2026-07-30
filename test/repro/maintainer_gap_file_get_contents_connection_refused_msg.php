<?php
declare(strict_types=1);

/**
 * Repro #25288 — http:// connect refused must surface strerror in open warning.
 * Zend: Failed to open stream: Connection refused
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    echo 'WARN:'.$m."\n";

    return true;
});

$r = @file_get_contents('http://127.0.0.1:9/');
var_export($r);
echo "\n";
