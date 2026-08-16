<?php

/**
 * DirectoryIterator family null/empty path — Argument #1 ($directory) cannot be empty (#31512).
 * php-src: ext/spl/spl_directory.c / spl_directory.stub.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    echo 'DEP:', $m, "\n";

    return true;
});

foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'] as $cls) {
    echo '== ', $cls, " ==\n";
    try {
        new $cls(null);
        echo "uncaught\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
    try {
        new $cls('');
        echo "uncaught\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
