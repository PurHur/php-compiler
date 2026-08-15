<?php
/** mkdir null permissions under strict_types (#31211) */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
$dir = sys_get_temp_dir() . '/phpc_mkdir_null_perms_' . getmypid();
@rmdir($dir);
try {
    var_export(mkdir($dir, null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
if (is_dir($dir)) {
    rmdir($dir);
}
