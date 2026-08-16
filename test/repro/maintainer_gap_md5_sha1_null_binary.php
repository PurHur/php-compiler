<?php
/** md5/sha1(..., null) $binary under strict_types (#31358) */
declare(strict_types=1);
error_reporting(E_ALL);
try {
    var_export(md5('x', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
try {
    var_export(sha1('x', null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
