<?php
/**
 * #21221 / re-#19333 — soft-null escapeshell* on PROFILE=8.4 (Zend DEP+coerce).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP ";
        return true;
    }
    return false;
});
foreach (['escapeshellarg', 'escapeshellcmd'] as $fn) {
    try {
        echo $fn, '=', var_export($fn(null), true), "\n";
    } catch (TypeError $e) {
        echo $fn, ": TypeError\n";
    } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
