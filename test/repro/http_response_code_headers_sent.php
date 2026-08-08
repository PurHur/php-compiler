<?php
error_reporting(E_ALL);
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
echo "body\n";
$got = http_response_code(201);
$now = http_response_code();
echo "got="; var_export($got); echo "\nnow="; var_export($now); echo "\n";
echo "warnings=", count($warnings), "\n";
if ($warnings) { echo $warnings[0], "\n"; }
