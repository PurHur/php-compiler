<?php
/** scandir null $sorting_order under strict_types (#31244) */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    scandir('.', null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
