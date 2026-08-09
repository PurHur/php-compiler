<?php
// AOT-friendly repro #29421 (no set_error_handler closures).
error_reporting(E_ALL);
try {
    substr_count('aaa', null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    substr_count('aaa', '');
    echo "empty_uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
