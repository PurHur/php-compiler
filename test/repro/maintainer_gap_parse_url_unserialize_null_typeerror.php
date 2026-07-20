<?php
// #21223 — unserialize(null) soft-null under PROFILE=8.4 (was TypeError under mistaken #19222).
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
echo var_export(unserialize(null), true), "\n";
echo 'depr=', (int) ($seen >= 1), "\n";
