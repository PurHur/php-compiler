<?php

/**
 * Repro #31192 — get_headers() excess argc uses Zend "at most 3" wording.
 * php-src: ext/standard/head.c
 */
try {
    get_headers('http://example.com', false, null, 'x');
    echo "excess:NO_THROW\n";
} catch (Throwable $e) {
    echo 'excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    get_headers();
    echo "missing:NO_THROW\n";
} catch (Throwable $e) {
    echo 'missing:', get_class($e), ':', $e->getMessage(), "\n";
}
