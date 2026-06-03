<?php
try {
    array_combine('keys', [1]);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
