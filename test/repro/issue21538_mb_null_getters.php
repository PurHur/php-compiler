<?php
/** Repro #21538 — mb_* (null) selects getter (php-src Z_PARAM_*_OR_NULL). */
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        ++$deps;
        echo "DEP\n";
    }
    return true;
});
try {
    echo var_export(mb_internal_encoding(null), true), "\n";
    echo var_export(mb_http_output(null), true), "\n";
    echo var_export(mb_language(null), true), "\n";
    echo $deps > 0 ? "UNEXPECTED_DEP\n" : "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
