<?php

declare(strict_types=1);

// Maintainer gap #17052 — date_create_from_format(null, …) under strict_types must TypeError.
try {
    date_create_from_format(null, '2024-01-15');
    fwrite(STDERR, "fail: expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
