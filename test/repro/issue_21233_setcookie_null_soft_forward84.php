<?php
/**
 * #21233 — setcookie/setrawcookie(null) soft-null on PROFILE=8.4
 * (php-src ext/standard/head.c; reverts over-strict #21003 TypeError).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }

    return false;
});
foreach (['setcookie', 'setrawcookie'] as $fn) {
    try {
        $fn(null);
        echo $fn, " OK\n";
    } catch (Throwable $e) {
        echo $fn, ' ', get_class($e), ' ', $e->getMessage(), "\n";
    }
}
