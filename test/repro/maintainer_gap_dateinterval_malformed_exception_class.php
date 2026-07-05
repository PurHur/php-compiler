<?php

declare(strict_types=1);

// Issue #16490 — 8.2 reference profile must throw plain Exception (ext/date/php_date.c).
try {
    new DateInterval('P');
    fwrite(STDERR, "no throw\n");
    exit(1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
