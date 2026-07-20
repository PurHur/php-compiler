<?php
/**
 * #21221 — escapeshellarg/escapeshellcmd(null) soft-null on PROFILE=8.4
 * (php-src ext/standard/exec.c; reverts over-strict #19333 TypeError).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
$n = null;
foreach (['escapeshellarg', 'escapeshellcmd'] as $fn) {
    try {
        echo $fn, '=', var_export($fn($n), true), "\n";
    } catch (Throwable $e) {
        echo $fn, ' ', get_class($e), ' ', $e->getMessage(), "\n";
    }
}
