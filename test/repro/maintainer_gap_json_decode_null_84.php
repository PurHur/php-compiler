<?php
// #18852 / #21223 — json_decode(null) soft-null DEP+coerce on PHP_COMPILER_PROFILE=8.4.
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
echo var_export(json_decode(null), true), "\n";
echo 'depr=', (int) ($seen >= 1), "\n";
