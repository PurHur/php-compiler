<?php
enum E: int { case A = 1; }

try {
    memory_get_usage(E::A);
    echo "memory_get_usage: NO EXCEPTION\n";
} catch (\Throwable $e) {
    echo 'memory_get_usage: ', $e::class, ': ', $e->getMessage(), "\n";
}

try {
    memory_get_peak_usage(E::A);
    echo "memory_get_peak_usage: NO EXCEPTION\n";
} catch (\Throwable $e) {
    echo 'memory_get_peak_usage: ', $e::class, ': ', $e->getMessage(), "\n";
}

try {
    memory_get_usage(true, 1);
    echo "extra args: NO EXCEPTION\n";
} catch (\Throwable $e) {
    echo 'extra args: ', $e::class, ': ', $e->getMessage(), "\n";
}
