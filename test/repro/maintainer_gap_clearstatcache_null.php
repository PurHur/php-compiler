<?php
/** clearstatcache(null) under strict_types (#31245) */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(clearstatcache(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
