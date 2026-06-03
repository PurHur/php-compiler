<?php
declare(strict_types=1);

set_error_handler(static function (int $errno, string $message): bool {
    echo 'W:', $message, "\n";

    return true;
});
var_export(-'0x10');
echo "\n";
var_export(-'42');
echo "\n";
