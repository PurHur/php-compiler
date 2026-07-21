<?php
// #21537 AOT smoke — soft-null DEP+unknown (not TypeError exit 255)
error_reporting(E_ALL);
set_error_handler(static function (int $no): bool {
    return E_DEPRECATED === $no;
});
$info = password_get_info(null);
echo (is_array($info) && ($info['algoName'] ?? '') === 'unknown') ? "OK\n" : "BAD\n";
