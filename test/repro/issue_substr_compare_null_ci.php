<?php
// #29756 — substr_compare(..., null) $case_insensitive under strict_types → TypeError
declare(strict_types=1);

try {
    var_dump(substr_compare('abc', 'ab', 0, 2, null));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
