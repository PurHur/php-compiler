<?php
// Repro #20113 / #21223: parse_str(null) soft-null under PHP_COMPILER_PROFILE=8.4.
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
    }
    return true;
});
parse_str(null, $o);
echo var_export($o, true), "\n";
echo 'depr=', (int) ($seen >= 1), "\n";
