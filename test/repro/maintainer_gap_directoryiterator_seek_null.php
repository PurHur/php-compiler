<?php
/** Maintainer gap: DirectoryIterator::seek(null) silent — Zend E_DEPRECATED (ext/spl/spl_directory.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$tmpdir = sys_get_temp_dir() . '/phpc_di_seek_' . getmypid();
@mkdir($tmpdir);
file_put_contents("$tmpdir/a.txt", 'x');

try {
    $it = new DirectoryIterator($tmpdir);
    $it->seek(null);
    echo 'key=' . $it->key() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
@system('rm -rf ' . escapeshellarg($tmpdir));
