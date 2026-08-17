<?php
/** Maintainer gap: FilesystemIterator::setFlags(null) TypeError — Zend E_DEPRECATED soft coerce (ext/spl/spl_directory.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$tmpdir = sys_get_temp_dir() . '/phpc_fsi_setflags_' . getmypid();
@mkdir($tmpdir);
file_put_contents("$tmpdir/a.txt", 'x');

try {
    $it = new FilesystemIterator($tmpdir);
    echo 'before=' . $it->getFlags() . "\n";
    $it->setFlags(null);
    echo 'after=' . $it->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
@system('rm -rf ' . escapeshellarg($tmpdir));
