<?php
declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    fwrite(STDERR, "E{$n}:{$m}\n");

    return true;
});

try {
    $r = getrusage(null);
    echo is_array($r) ? "array\n" : var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$n = null;
try {
    $r = getrusage($n);
    echo is_array($r) ? "array-var\n" : var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
