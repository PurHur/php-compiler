<?php
/**
 * session_regenerate_id(null) under strict_types — TypeError (#31444 / keep #30419).
 */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(session_regenerate_id(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
