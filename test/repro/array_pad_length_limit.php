<?php

// #26658 — array_pad() oversized pad amount → ValueError (no OOM).
// php-src 8.2: ext/standard/array.c — pad_size_abs - input_size > 1048576
try {
    array_pad([1], 1048578, 0);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
