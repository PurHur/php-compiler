<?php
/** Issue #6293 — odbc_connect exists + invalid DSN fails with warning. */
echo function_exists('odbc_connect') ? "yes\n" : "no\n";
echo function_exists('odbc_exec') ? "yes\n" : "no\n";
$warned = false;
set_error_handler(static function (int $errno, string $errstr) use (&$warned): bool {
    if (E_WARNING === $errno && str_contains($errstr, 'SQLConnect')) {
        $warned = true;
        return true;
    }
    return false;
});
$r = odbc_connect('php-compiler-invalid-dsn', 'u', 'p');
echo false === $r ? "false\n" : "other\n";
echo $warned ? "warned\n" : "no-warn\n";
