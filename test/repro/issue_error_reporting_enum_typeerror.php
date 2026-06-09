<?php
// Issue #5917 — error_reporting() enum case level must TypeError (ext/standard/basic_functions.c).
enum Es: string { case B = '1'; }
try {
    error_reporting(Es::B);
    echo "no_error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
