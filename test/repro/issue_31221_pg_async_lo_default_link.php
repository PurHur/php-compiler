<?php
// #31221 — omitted-connection pg_end_copy/untrace/put_line/lo_*/trace FETCH_DEFAULT_LINK.
error_reporting(E_ALL);
ini_set('display_errors', '1');

set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno || E_USER_DEPRECATED === $errno) {
        echo 'DEP: ', $errstr, "\n";

        return true;
    }

    return false;
});

$cases = [
    'pg_end_copy' => static fn () => pg_end_copy(),
    'pg_untrace' => static fn () => pg_untrace(),
    'pg_put_line' => static fn () => pg_put_line('x'),
    'pg_lo_create' => static fn () => pg_lo_create(),
    'pg_lo_unlink' => static fn () => pg_lo_unlink(1),
    'pg_lo_import' => static fn () => pg_lo_import('/tmp/x'),
    'pg_lo_export' => static fn () => pg_lo_export(1, '/tmp/x'),
    'pg_trace' => static fn () => pg_trace('/tmp/pgtrace.out'),
];

foreach ($cases as $label => $fn) {
    echo "=== $label ===\n";
    try {
        $r = $fn();
        echo 'ret=', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
