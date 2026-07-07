<?php

declare(strict_types=1);

/**
 * Maintainer repro: is_uploaded_file(null) under strict_types (#17061, ext/standard/filestat.c).
 */

try {
    is_uploaded_file(null);
    echo "fail: expected TypeError under strict_types\n";
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type string, null given')) {
        echo 'fail: unexpected message: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
