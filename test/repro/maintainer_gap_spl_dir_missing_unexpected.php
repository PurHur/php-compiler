<?php

/**
 * DirectoryIterator family missing path — UnexpectedValueException + class name (#31506).
 * php-src: ext/spl/spl_directory.c
 */
error_reporting(E_ALL);
$path = '/no/such/dir_'.bin2hex(random_bytes(4));
foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'] as $cls) {
    echo '== ', $cls, " ==\n";
    try {
        new $cls($path);
        echo "uncaught\n";
    } catch (Throwable $e) {
        // Normalize unique path for Zend vs VM comparison.
        $msg = preg_replace('#/no/such/dir_[a-f0-9]+#', '/no/such/DIR', $e->getMessage()) ?? $e->getMessage();
        echo get_class($e), ': ', $msg, "\n";
    }
}
