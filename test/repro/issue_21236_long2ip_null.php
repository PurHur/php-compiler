<?php
// Repro #21236 — long2ip(null) on PROFILE=8.4 (php-src Z_PARAM_LONG soft-null).
declare(strict_types=0);
set_error_handler(static function (): bool { echo "DEP\n"; return true; });
try {
    echo var_export(long2ip(null), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), PHP_EOL;
}
