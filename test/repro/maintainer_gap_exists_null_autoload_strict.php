<?php

declare(strict_types=1);

/**
 * #31443 — null $autoload under strict_types → TypeError.
 */
error_reporting(E_ALL);
try {
    class_exists('stdClass', null);
    echo "unexpected ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
