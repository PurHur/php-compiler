<?php
// #31220 — 0-arg pg_host/…/ping FETCH_DEFAULT_LINK E_DEPRECATED + Error.
error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
        echo 'DEP: ', $errstr, "\n";

        return true;
    }

    return false;
});

foreach (['pg_host', 'pg_dbname', 'pg_port', 'pg_tty', 'pg_options', 'pg_client_encoding', 'pg_version', 'pg_ping'] as $f) {
    echo "=== $f ===\n";
    try {
        $r = $f();
        echo 'ret=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

try {
    pg_parameter_status('server_version');
    echo "parameter_status=ok\n";
} catch (Throwable $e) {
    echo 'parameter_status=', get_class($e), ': ', $e->getMessage(), "\n";
}
