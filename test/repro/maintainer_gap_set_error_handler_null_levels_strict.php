<?php
// #31465 — strict_types: set_error_handler($cb, null) → TypeError ($error_levels)
declare(strict_types=1);
error_reporting(E_ALL);
try {
    set_error_handler(static function (): bool {
        return false;
    }, null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
