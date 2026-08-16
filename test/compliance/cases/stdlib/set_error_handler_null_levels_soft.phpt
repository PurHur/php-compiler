--TEST--
Stdlib: set_error_handler(..., null) soft-null DEP for $error_levels (VM, #31465, Zend/zend_builtin_functions.c)
--FILE--
<?php
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
--EXPECT--
prev_callable=true
ERR[8192]: set_error_handler(): Passing null to parameter #2 ($error_levels) of type int is deprecated
