<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    tempnam(sys_get_temp_dir(), null);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
