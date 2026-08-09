<?php
// #29504 — substr_compare(..., null) $offset soft-null DEP+coerce (php-src-strict)
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    echo "WARN[$no]: $str\n";

    return true;
});
try {
    var_export(substr_compare('abc', 'b', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
