<?php
// #29393 — Zend soft-null $characters under PROFILE=8.4 (DEP + empty mask → 0).
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        fwrite(STDOUT, "DEP:$errstr\n");

        return true;
    }

    return false;
});
$r = strspn('abc', null);
echo "RESULT:$r\n";
