<?php
/** dirname null levels under strict_types (#31210) */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    var_export(dirname('/a/b/c', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
