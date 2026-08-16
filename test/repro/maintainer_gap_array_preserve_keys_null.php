<?php
/** array_slice/chunk/reverse(..., null) $preserve_keys — soft DEP+coerce (#31442). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== soft null ===\n";
try {
    var_export(array_slice([1, 2, 3], 0, 1, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(array_chunk([1, 2], 1, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(array_reverse([1, 2], null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
