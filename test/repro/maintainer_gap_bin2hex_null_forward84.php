<?php
// Repro #20154 — bin2hex(null) TypeError under PHP_COMPILER_PROFILE=8.4 (php-src ext/standard/string.c)
try {
    var_export(bin2hex(null));
    echo "\nCOERCED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
