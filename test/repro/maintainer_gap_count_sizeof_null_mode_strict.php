<?php
declare(strict_types=1);
/** count(..., null) $mode under strict_types → TypeError (#31463). */
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    count([1, 2], null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
