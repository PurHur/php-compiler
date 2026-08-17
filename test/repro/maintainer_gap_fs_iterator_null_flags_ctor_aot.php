<?php
/**
 * AOT probe: FilesystemIterator/GlobIterator null $flags soft-null (#31721).
 * No set_error_handler (AOT #1379). Soft-null DEPs on stderr.
 * Avoid mkdir/system — NestedJIT mkdir can fail module verify on this path.
 * RecursiveDirectoryIterator has no thin-AOT construct — VM/JIT only.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$dir = __DIR__;

try {
    $fs = new FilesystemIterator($dir, null);
    echo "fs_ok\n";
    unset($fs);
} catch (Throwable $e) {
    echo 'fs:' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

try {
    $gi = new GlobIterator($dir . '/*', null);
    echo "gi_ok\n";
    unset($gi);
} catch (Throwable $e) {
    echo 'gi:' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
