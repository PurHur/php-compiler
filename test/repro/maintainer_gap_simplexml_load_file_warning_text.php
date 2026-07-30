<?php

declare(strict_types=1);

$path = '/nonexistent/path/simplexml_load_file_warning_25295.xml';
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$result = @simplexml_load_file($path);
restore_error_handler();

echo ($result === false) ? "false\n" : "not-false\n";
echo count($warnings), "\n";
echo $warnings[0] ?? '', "\n";
echo str_contains($warnings[0] ?? '', 'I/O warning') ? "has_io_warning\n" : "missing_io_warning\n";
echo str_contains($warnings[0] ?? '', $path) ? "has_path\n" : "missing_path\n";
