<?php

/**
 * #30544 — filestat path predicates excess argc → ArgumentCountError (php-src filestat.c).
 */
error_reporting(E_ALL);

foreach (['is_file', 'is_dir', 'is_link', 'is_readable', 'is_writable', 'is_executable', 'file_exists', 'realpath'] as $fn) {
    try {
        $fn('/tmp', 1);
        echo "$fn: NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}

try {
    is_file();
    echo "is_file missing: NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
