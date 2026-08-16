<?php

/**
 * AOT-friendly flock(null) soft-null probe (#31462) — no set_error_handler closure.
 * Default deprecation + catchable ValueError.
 */
error_reporting(E_ALL);
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
fclose($fp);
