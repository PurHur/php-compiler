<?php

// #29342 — array_pad() oversize ValueError wording (Zend abstract limit text).
// php-src: ext/standard/array.c — PHP_FUNCTION(array_pad)
try {
    array_pad([1], PHP_INT_MAX, 0);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
