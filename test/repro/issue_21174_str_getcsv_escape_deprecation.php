<?php
/**
 * Issue #21174 — str_getcsv() omitted $escape → E_DEPRECATED under PROFILE=8.4.
 * php-src: ext/standard/file.c PHP_FUNCTION(str_getcsv)
 */
set_error_handler(static function (int $no, string $msg): bool {
    if (\E_DEPRECATED === $no) {
        echo 'DEP:', $msg, "\n";

        return true;
    }

    return false;
});
$row = str_getcsv('a,b');
echo $row[0], ',', $row[1], "\n";
$row2 = str_getcsv('a,b', ',', '"', '\\');
echo 'explicit:', $row2[0], ',', $row2[1], "\n";
