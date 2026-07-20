<?php
/**
 * #21233 — setcookie(null)/setrawcookie(null) DEP + ValueError under PHP_COMPILER_PROFILE=8.4
 * (reverts over-strict #21003 TypeError; php-src ext/standard/head.c).
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
        echo "$fn: uncaught\n";
        exit(1);
    } catch (ValueError $e) {
        echo "$fn ValueError OK\n";
    } catch (TypeError $e) {
        echo "$fn unexpected TypeError: ", $e->getMessage(), "\n";
        exit(1);
    }
}
echo "OK\n";
