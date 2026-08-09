<?php

declare(strict_types=1);

// #29351 — Zend 8.3+ ValueError for increasing range + negative step
try {
    $r = range(1, 5, -1);
    echo 'inc=', implode(',', $r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    echo 'desc=', implode(',', range(5, 1, 1)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    echo 'eq=', implode(',', range(5, 5, -1)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
