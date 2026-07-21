<?php
// Guard #21705 — http_response_code(null) deprecation cites parameter #1 ($response_code) under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo $msg, "\n";

        return true;
    }

    return false;
});
$responseCode = null;
echo var_export(http_response_code($responseCode), true), "\n";
