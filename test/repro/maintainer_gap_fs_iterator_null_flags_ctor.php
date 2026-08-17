<?php
/** Maintainer gap: FilesystemIterator/RecursiveDirectoryIterator/GlobIterator::__construct(null $flags) TypeError — Zend E_DEPRECATED + flags=0 (ext/spl/spl_directory.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$tmpdir = sys_get_temp_dir() . '/phpc_fs_null_flags_' . getmypid();
@mkdir($tmpdir);
file_put_contents("$tmpdir/a.txt", 'x');

echo "== FilesystemIterator ==\n";
try {
    $it = new FilesystemIterator($tmpdir, null);
    echo 'flags=' . $it->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "== RecursiveDirectoryIterator ==\n";
try {
    $it = new RecursiveDirectoryIterator($tmpdir, null);
    echo 'flags=' . $it->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "== GlobIterator ==\n";
try {
    $it = new GlobIterator($tmpdir . '/*', null);
    echo 'flags=' . $it->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
@system('rm -rf ' . escapeshellarg($tmpdir));
