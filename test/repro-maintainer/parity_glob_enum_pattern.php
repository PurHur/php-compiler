<?php
declare(strict_types=1);

/** Issue #5732 — glob() must TypeError on enum case pattern (php-src Z_PARAM_STR). */
enum E { case A; }
enum Es: string { case P = '*.txt'; }

foreach ([E::A, Es::P] as $pattern) {
    try {
        var_export(glob($pattern));
        echo "\nuncaught\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
