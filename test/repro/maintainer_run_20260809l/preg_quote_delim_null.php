<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message): bool {
    fwrite(STDERR, "WARN[$severity]: $message\n");
    return true;
});
var_export(preg_quote('a.*', null));
echo "\n";
