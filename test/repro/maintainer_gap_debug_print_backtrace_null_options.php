<?php
/** debug_print_backtrace(null) $options under strict_types */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    debug_print_backtrace(null);
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
