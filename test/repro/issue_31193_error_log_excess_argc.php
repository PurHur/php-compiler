<?php

/**
 * Repro #31193 — error_log() excess argc uses Zend "at most 4" wording.
 * php-src: ext/standard/basic_functions.c
 */
try {
    error_log('m', 0, '', '', 'x');
    echo "excess:NO_THROW\n";
} catch (Throwable $e) {
    echo 'excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    error_log();
    echo "missing:NO_THROW\n";
} catch (Throwable $e) {
    echo 'missing:', get_class($e), ':', $e->getMessage(), "\n";
}
