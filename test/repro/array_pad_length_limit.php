<?php

// #26658 / #29342 — array_pad() oversized pad amount → ValueError (no OOM).
// php-src: ext/standard/array.c — pad-size guard; Zend wording (not raw 1048576).
try {
    array_pad([1], 1048578, 0);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
