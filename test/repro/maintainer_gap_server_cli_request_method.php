<?php

declare(strict_types=1);

/**
 * Maintainer gap: CLI $_SERVER['REQUEST_METHOD'] when unset (#14210, php-src-strict).
 */
$method = $_SERVER['REQUEST_METHOD'] ?? null;
if (null !== $method) {
    echo 'REQUEST_METHOD should be unset on CLI, got ', var_export($method, true), "\n";
    exit(1);
}
if (false !== getenv('REQUEST_METHOD')) {
    echo "getenv(REQUEST_METHOD) should be false on CLI\n";
    exit(1);
}

echo "ok\n";
