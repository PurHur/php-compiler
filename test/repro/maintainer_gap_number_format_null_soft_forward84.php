<?php

/**
 * #21429 — number_format(null) soft-null under PHP_COMPILER_PROFILE=8.4.
 * Expect: E_DEPRECATED + "0" (Zend php-src formatted_print.c / number_format.c).
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
    $r = number_format(null);
    echo var_export($r, true), "\n";
    echo "ALL_OK\n";
} catch (\Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
