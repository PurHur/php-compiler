<?php
/** chmod null permissions under strict_types (#31213) */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
$p = tempnam(sys_get_temp_dir(), 'ch');
try {
    var_export(chmod($p, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
@unlink($p);
