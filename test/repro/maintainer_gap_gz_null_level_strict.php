<?php

declare(strict_types=1);

/**
 * Issue #31445 — gzcompress(null level) under strict_types → TypeError.
 */
error_reporting(E_ALL);
try {
    gzcompress('a', null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
