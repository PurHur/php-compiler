<?php
/**
 * Repro #21514 — vprintf()/vfprintf()/fprintf() null $format soft-null under PROFILE=8.4
 * (Zend 8.4.23: E_DEPRECATED + coerce → 0; sibling of #21234 printf/fprintf).
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
    echo 'vprintf ', var_export(vprintf(null, []), true), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), PHP_EOL;
}
$fp = fopen('php://memory', 'w+');
try {
    echo 'vfprintf ', var_export(vfprintf($fp, null, []), true), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), PHP_EOL;
}
try {
    echo 'fprintf ', var_export(fprintf($fp, null), true), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), PHP_EOL;
}
fclose($fp);
echo 'depr=', (int) ($seen >= 3), PHP_EOL;
