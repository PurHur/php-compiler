<?php

declare(strict_types=1);

/**
 * Issue #13749 — date_sunrise(time(), SUNFUNCS_RET_STRING, …) inline constant form.
 */

try {
    $s = date_sunrise(time(), SUNFUNCS_RET_STRING, 40.7, -74.0);
    if (!\is_string($s) || '' === $s) {
        echo "fail: expected non-empty string\n";
        exit(1);
    }
    echo 'ok:', \strlen($s), "\n";
} catch (Throwable $e) {
    echo 'fail:', \get_class($e), ':', $e->getMessage(), "\n";
    exit(1);
}
