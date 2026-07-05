<?php

/**
 * Issue #16576 — stream_set_read_buffer()/stream_set_write_buffer() numeric-string $size.
 */

$h = fopen('php://memory', 'r+');
try {
    $read = stream_set_read_buffer($h, '0');
    $write = stream_set_write_buffer($h, '0');
    echo 'ok:', var_export($read, true), ':', var_export($write, true), "\n";
} catch (Throwable $e) {
    echo 'fail:', get_class($e), "\n";
}
fclose($h);
