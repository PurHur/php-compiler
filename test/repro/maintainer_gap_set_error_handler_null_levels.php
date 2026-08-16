<?php
// #31465 — set_error_handler($cb, null) soft-null DEP for $error_levels (Zend/zend_builtin_functions.c)
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $errstr) use (&$seen): bool {
    $seen[] = [$errno, $errstr];

    return true;
});
try {
    $prev = set_error_handler(static function (): bool {
        return false;
    }, null);
    echo 'prev_callable=', var_export(is_callable($prev), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
foreach ($seen as $row) {
    echo 'ERR[', $row[0], ']: ', $row[1], "\n";
}
