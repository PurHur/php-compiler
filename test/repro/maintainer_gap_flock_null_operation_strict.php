<?php

declare(strict_types=1);

/**
 * flock($stream, null) with strict_types — TypeError, no DEP (#31462).
 */
error_reporting(E_ALL);
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "no_throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
} finally {
    fclose($fp);
}
