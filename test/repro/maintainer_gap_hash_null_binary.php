<?php
/** hash(..., null) $binary under strict_types (#31288) */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(hash('md5', 'a', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
