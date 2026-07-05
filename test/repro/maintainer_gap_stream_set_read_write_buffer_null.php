<?php

/**
 * Issue #16574 — stream_set_read_buffer()/stream_set_write_buffer() null $size coercion.
 */

$h = fopen('php://memory', 'r+');
try {
    $read = stream_set_read_buffer($h, null);
    $write = stream_set_write_buffer($h, null);
    echo 'ok:', var_export($read, true), ':', var_export($write, true), "\n";
} catch (Throwable $e) {
    echo 'fail:', get_class($e), ':', $e->getMessage(), "\n";
}
fclose($h);
