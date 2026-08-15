<?php
/** intval null base under strict_types (#31227) */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    var_export(intval('10', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
