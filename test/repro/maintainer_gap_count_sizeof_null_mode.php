<?php
/** count/sizeof(..., null) $mode — soft DEP + COUNT_NORMAL (#31463). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== soft null ===\n";
try {
    echo 'count=', count([1, 2], null), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'sizeof=', sizeof([1, 2], null), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
