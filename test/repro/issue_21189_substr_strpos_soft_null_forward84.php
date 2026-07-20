<?php
// Repro #21189 — substr/strpos/strstr/explode/str_replace soft-null under PROFILE=8.4
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
    }

    return true;
});
echo 'substr ', substr(null, 0) === '' ? 'OK' : 'BAD', "\n";
echo 'strpos ', strpos(null, 'a') === false ? 'OK' : 'BAD', "\n";
echo 'strstr ', strstr(null, 'a') === false ? 'OK' : 'BAD', "\n";
$ex = explode(',', null);
echo 'explode ', (is_array($ex) && 1 === count($ex) && '' === $ex[0]) ? 'OK' : 'BAD', "\n";
echo 'str_replace ', str_replace('a', 'b', null) === '' ? 'OK' : 'BAD', "\n";
