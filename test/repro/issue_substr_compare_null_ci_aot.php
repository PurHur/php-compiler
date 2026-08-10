<?php
// #29756 — AOT: substr_compare(..., null) $case_insensitive under strict_types → TypeError
declare(strict_types=1);

try {
    echo substr_compare('abc', 'ab', 0, 2, null), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
