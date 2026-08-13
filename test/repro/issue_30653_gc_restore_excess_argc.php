<?php
/**
 * gc_enabled / restore_error_handler / restore_exception_handler excess argc → ArgumentCountError (#30653).
 * php-src: ext/standard/basic_functions.c
 */
try {
    gc_enabled(1);
    echo "gc_enabled_0:OK\n";
} catch (ArgumentCountError $e) {
    echo 'gc_enabled_0:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    gc_enabled(1, 2);
    echo "gc_enabled_1:OK\n";
} catch (ArgumentCountError $e) {
    echo 'gc_enabled_1:ArgumentCountError:', $e->getMessage(), "\n";
}
echo 'gc_enabled_2:OK:', gc_enabled() ? 'true' : 'false', "\n";

try {
    restore_error_handler(1);
    echo "restore_error_handler_0:OK\n";
} catch (ArgumentCountError $e) {
    echo 'restore_error_handler_0:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    restore_error_handler(1, 2);
    echo "restore_error_handler_1:OK\n";
} catch (ArgumentCountError $e) {
    echo 'restore_error_handler_1:ArgumentCountError:', $e->getMessage(), "\n";
}
echo 'restore_error_handler_2:OK:', restore_error_handler() ? 'true' : 'false', "\n";

try {
    restore_exception_handler(1);
    echo "restore_exception_handler_0:OK\n";
} catch (ArgumentCountError $e) {
    echo 'restore_exception_handler_0:ArgumentCountError:', $e->getMessage(), "\n";
}
try {
    restore_exception_handler(1, 2);
    echo "restore_exception_handler_1:OK\n";
} catch (ArgumentCountError $e) {
    echo 'restore_exception_handler_1:ArgumentCountError:', $e->getMessage(), "\n";
}
echo 'restore_exception_handler_2:OK:', restore_exception_handler() ? 'true' : 'false', "\n";
