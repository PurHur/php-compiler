<?php

/**
 * Issue #21429 / re-#21379 — number_format(null) soft-null on PHP_COMPILER_PROFILE=8.4.
 * Zend 8.4: E_DEPRECATED + "0" (not TypeError). No declare(strict_types=1).
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});

try {
    echo var_export(number_format(null), true), "\n";
    echo "ALL_OK\n";
} catch (\Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
