<?php
/**
 * Repro #21521 — sscanf() null $format soft-null under PROFILE=8.4
 * (Zend 8.4.23: E_DEPRECATED + coerce → array(); re-#21209 over-strict TypeError).
 */
error_reporting(E_ALL);
$seen = 0;
set_error_handler(static function (int $no) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen++;
        return true;
    }
    return false;
});
try {
    echo 'sscanf ', var_export(sscanf('abc', null), true), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), PHP_EOL;
}
echo 'depr=', (int) ($seen >= 1), PHP_EOL;
